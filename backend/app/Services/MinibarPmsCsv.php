<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

final class MinibarPmsCsv
{
    public function parse(string $bytes,array $map,string $day): array
    {
        if (!mb_check_encoding($bytes,'UTF-8') || str_contains($bytes,"\0")) {
            $this->invalid('Le fichier doit être encodé en UTF-8.');
        }
        $bytes = preg_replace('/^\xEF\xBB\xBF/','',$bytes);
        $lines = preg_split('/\r\n|\n|\r/',rtrim($bytes,"\r\n"));
        if (count($lines) < 2 || count($lines) > 5001) {
            $this->invalid('Le fichier doit contenir entre 1 et 5 000 lignes de données.');
        }
        $split = function (string $line,int $number) use ($map) {
            $sep = preg_quote($map['delimiter'],'/');
            $field = '(?:"(?:[^"]|"")*"|[^"'.$sep.']*)';
            if (!preg_match('/^'.$field.'(?:'.$sep.$field.')*$/Du',$line)) {
                $this->invalid("Guillemets ou séparateur invalides, ligne $number.");
            }
            return str_getcsv($line,$map['delimiter'],'"','');
        };
        $header = $split(array_shift($lines),1);
        $columns = [$map['date_header'],$map['room_header'],$map['amount_header']];
        if ($map['mode'] === 'room_product') {
            $columns[] = $map['sku_header'];
        }
        if (count(array_unique($columns)) !== count($columns) || count(array_unique($header)) !== count($header)
            || array_diff($columns,$header)) {
            $this->invalid('Les colonnes sélectionnées doivent exister et être distinctes.');
        }
        $rows = [];
        foreach ($lines as $offset=>$line) {
            $number = $offset+2;
            $values = $split($line,$number);
            if (count($values) !== count($header)) {
                $this->invalid("Nombre de colonnes invalide, ligne $number.");
            }
            $data = array_combine($header,$values);
            $room = trim($data[$map['room_header']]);
            $sku = $map['mode'] === 'room_product' ? trim($data[$map['sku_header']]) : null;
            if ($data[$map['date_header']] !== $day || $room === '' || mb_strlen($room)>30
                || preg_match('/[\x00-\x1f]/',$room)
                || ($sku !== null && !preg_match('/^[A-Z0-9][A-Z0-9._-]{0,39}$/D',$sku))) {
                $this->invalid("Journée, chambre ou article invalide, ligne $number.");
            }
            $amount = trim($data[$map['amount_header']]);
            if ($map['amount_unit'] === 'cents') {
                if (!preg_match('/^\d{1,12}$/D',$amount)) {
                    $this->invalid("Montant en centimes invalide, ligne $number.");
                }
                $cents = (int)$amount;
            } else {
                if (!preg_match('/^(\d{1,10})(?:[,.](\d{1,2}))?$/D',$amount,$matches)) {
                    $this->invalid("Montant en euros invalide, ligne $number.");
                }
                $cents = 100*(int)$matches[1]+(int)str_pad($matches[2]??'',2,'0');
            }
            $rows[] = ['room'=>$room,'sku'=>$sku,'amount_cents'=>$cents];
        }
        return $rows;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file'=>$message]);
    }
}
