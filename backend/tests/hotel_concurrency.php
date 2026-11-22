<?php

/**
 * P131: truly independent PostgreSQL processes contend for the SAME room.
 * A disposable atlas_test database is required; never run against production.
 *
 * Exit codes for workers: 0 committed, 3 rejected by business rule, 4 unexpected.
 */
use App\Models\Company;
use App\Models\HotelBooking;
use App\Models\HotelProperty;
use App\Models\HotelRoom;
use App\Models\HotelRoomBlock;
use App\Models\Role;
use App\Models\User;
use App\Services\HotelRoomInventory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$db = config('database.connections.pgsql.database');
if (DB::getDriverName() !== 'pgsql' || ! is_string($db) || ! str_ends_with($db, '_test')) {
    fwrite(STDERR, "REFUSED: use a disposable PostgreSQL database ending in _test.\n");
    exit(1);
}

if (($argv[1] ?? '') === 'worker') {
    try {
        $user = User::where('email', 'hotel-concurrent@example.test')->firstOrFail();
        Auth::login($user);
        $action = $argv[2] ?? '';
        $roomId = (int) ($argv[3] ?? 0);
        $bookingId = (int) ($argv[4] ?? 0);
        $start = $argv[5] ?? '';
        $end = $argv[6] ?? '';

        // Wait until both workers exist, maximizing the real lock race.
        $barrier = $argv[7] ?? '';
        for ($i = 0; $i < 200 && ! file_exists($barrier); $i++) {
            usleep(10000);
        }
        if (! file_exists($barrier)) {
            throw new RuntimeException('Barrier timeout');
        }

        DB::transaction(function () use ($action, $roomId, $bookingId, $start, $end, $user): void {
            // Production confirmation first locks the booking, then room;
            // block creation locks the same room. Rechecks happen under lock.
            $booking = null;
            if ($action === 'confirm') {
                $booking = HotelBooking::query()->lockForUpdate()->findOrFail($bookingId);
                if ($booking->status !== 'draft' || $booking->room_id !== $roomId) {
                    throw new DomainException('Booking is no longer confirmable');
                }
            }
            $room = HotelRoom::query()->lockForUpdate()->findOrFail($roomId);
            $hotel = HotelProperty::query()->findOrFail($room->hotel_id);
            $reasons = app(HotelRoomInventory::class)->reasons(
                $hotel, $room, $start, $end, $booking?->id
            );
            if ($reasons !== []) {
                throw new DomainException('Room already reserved or blocked');
            }

            if ($action === 'confirm') {
                $booking->status = 'confirmed';
                $booking->save();
            } elseif (in_array($action, ['hold', 'maintenance'], true)) {
                HotelRoomBlock::create([
                    'hotel_id' => $hotel->id,
                    'room_id' => $room->id,
                    'kind' => $action,
                    'status' => 'active',
                    'starts_on' => $start,
                    'ends_on' => $end,
                    'expires_at' => $action === 'hold' ? now()->addHours(4) : null,
                    'reason' => 'Synthetic PostgreSQL concurrency test',
                    'created_by' => $user->id,
                ]);
            } else {
                throw new InvalidArgumentException('Unknown worker action');
            }
        }, 3);
        exit(0);
    } catch (DomainException $e) {
        exit(3);
    } catch (Throwable $e) {
        fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
        exit(4);
    }
}

Artisan::call('migrate:fresh', ['--force' => true]);
$role = Role::where('name', 'admin')->firstOrFail();
$company = Company::create(['code' => 'CONCURRENT_HOTEL', 'name' => 'Concurrent Hotel Test']);
$user = User::create([
    'name' => 'Hotel test operator',
    'email' => 'hotel-concurrent@example.test',
    'password' => 'RandomTestOnlyPassword2026!',
    'role_id' => $role->id,
]);
$user->companies()->attach($company->id, ['role_id' => $role->id]);
Auth::login($user);
$hotel = HotelProperty::create(['code' => 'TEST', 'name' => 'Synthetic Hotel', 'timezone' => 'Africa/Tunis']);

function competingWorkers(array $workers): array
{
    $barrier = sys_get_temp_dir().'/atlas-hotel-race-'.bin2hex(random_bytes(12));
    $processes = [];
    try {
        foreach ($workers as $worker) {
            $command = array_merge([PHP_BINARY, __FILE__, 'worker'], $worker, [$barrier]);
            $pipes = [];
            $process = proc_open($command, [
                0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
            ], $pipes);
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to launch PostgreSQL worker');
            }
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        touch($barrier);
        $codes = [];
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);
            if ($output !== '' || $error !== '') {
                echo $output.$error;
            }
            $codes[] = $code;
        }
        sort($codes);
        return $codes;
    } finally {
        @unlink($barrier);
    }
}

function draftBooking(int $hotelId, int $roomId, int $userId, string $reference, string $start, string $end): int
{
    return HotelBooking::create([
        'hotel_id' => $hotelId,
        'room_id' => $roomId,
        'reference' => $reference,
        'guest_display_name' => 'Synthetic guest',
        'arrival_date' => $start,
        'departure_date' => $end,
        'status' => 'draft',
        'created_by' => $userId,
    ])->id;
}

function newRoom(int $hotelId, string $number): int
{
    return HotelRoom::create([
        'hotel_id' => $hotelId,
        'room_number' => $number,
        'category' => 'Double',
        'room_status' => 'available',
    ])->id;
}

$room = newRoom($hotel->id, '101');
$a = draftBooking($hotel->id, $room, $user->id, 'RACE-1', '2026-12-01', '2026-12-04');
$b = draftBooking($hotel->id, $room, $user->id, 'RACE-2', '2026-12-02', '2026-12-05');
$codes = competingWorkers([
    ['confirm', (string) $room, (string) $a, '2026-12-01', '2026-12-04'],
    ['confirm', (string) $room, (string) $b, '2026-12-02', '2026-12-05'],
]);
if ($codes !== [0, 3] || HotelBooking::query()->where('room_id', $room)->where('status', 'confirmed')->count() !== 1) {
    throw new RuntimeException('FAIL concurrent overlapping booking confirmations: '.json_encode($codes));
}
echo "PASS two independent PostgreSQL booking confirmations: exactly one succeeds.\n";

$room2 = newRoom($hotel->id, '102');
$c = draftBooking($hotel->id, $room2, $user->id, 'RACE-3', '2026-12-10', '2026-12-12');
$codes = competingWorkers([
    ['confirm', (string) $room2, (string) $c, '2026-12-10', '2026-12-12'],
    ['hold', (string) $room2, '0', '2026-12-10', '2026-12-12'],
]);
$committed = HotelBooking::query()->where('room_id', $room2)->where('status', 'confirmed')->count()
    + HotelRoomBlock::query()->where('room_id', $room2)->where('status', 'active')->count();
if ($codes !== [0, 3] || $committed !== 1) {
    throw new RuntimeException('FAIL concurrent hold against booking: '.json_encode($codes));
}
echo "PASS simultaneous hold vs booking confirmation: exactly one succeeds.\n";

$room3 = newRoom($hotel->id, '103');
$d = draftBooking($hotel->id, $room3, $user->id, 'RACE-4', '2026-12-20', '2026-12-22');
$codes = competingWorkers([
    ['confirm', (string) $room3, (string) $d, '2026-12-20', '2026-12-22'],
    ['maintenance', (string) $room3, '0', '2026-12-20', '2026-12-22'],
]);
$committed = HotelBooking::query()->where('room_id', $room3)->where('status', 'confirmed')->count()
    + HotelRoomBlock::query()->where('room_id', $room3)->where('status', 'active')->count();
if ($codes !== [0, 3] || $committed !== 1) {
    throw new RuntimeException('FAIL concurrent maintenance against booking: '.json_encode($codes));
}
echo "PASS simultaneous maintenance vs booking confirmation: exactly one succeeds.\n";

$room4 = newRoom($hotel->id, '104');
$e = draftBooking($hotel->id, $room4, $user->id, 'ADJ-1', '2026-12-01', '2026-12-03');
$f = draftBooking($hotel->id, $room4, $user->id, 'ADJ-2', '2026-12-03', '2026-12-05');
$codes = competingWorkers([
    ['confirm', (string) $room4, (string) $e, '2026-12-01', '2026-12-03'],
    ['confirm', (string) $room4, (string) $f, '2026-12-03', '2026-12-05'],
]);
if ($codes !== [0, 0] || HotelBooking::query()->where('room_id', $room4)->where('status', 'confirmed')->count() !== 2) {
    throw new RuntimeException('FAIL same-day checkout/checkin concurrency: '.json_encode($codes));
}
echo "PASS concurrent adjacent check-out/check-in dates: both succeed.\n";
