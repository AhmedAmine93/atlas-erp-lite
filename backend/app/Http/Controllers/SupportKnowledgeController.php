<?php

namespace App\Http\Controllers;

use App\Models\SupportKnowledgeArticle;
use App\Services\Audit;
use App\Services\TenantContext;
use App\Services\TenantRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SupportKnowledgeController extends Controller
{
    private function role(Request $request): string
    {
        return app(TenantRoles::class)->role($request->user())?->name ?? 'none';
    }

    private function visible($query, Request $request)
    {
        if (! in_array($this->role($request), ['admin','support_n3'], true)) {
            $query->where('classification', '!=', 'n3');
        }
        return $query;
    }

    private function revision(SupportKnowledgeArticle $article, Request $request, string $action): void
    {
        DB::table('support_knowledge_revisions')->insert([
            'company_id' => app(TenantContext::class)->requireId(),
            'article_id' => $article->id, 'actor_id' => $request->user()->id,
            'version' => $article->version, 'action' => $action,
            'snapshot' => json_encode($article->only([
                'title', 'content', 'classification', 'status',
                'critical', 'owner_id', 'reviewer_id', 'next_review_at',
            ]), JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
        Audit::record('support.knowledge.'.$action, $article, ['version' => $article->version]);
    }

    public function index(Request $request)
    {
        Gate::authorize('itsmRead');
        $data = $request->validate(['search' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        $query = SupportKnowledgeArticle::query();
        $this->visible($query, $request);
        // Draft / retired articles require contributor privileges.
        if (! in_array($this->role($request), ['admin','support_n3','support_manager'], true)) {
            $query->where('status', 'published');
        }
        if (! empty($data['search'])) {
            $query->whereRaw('LOWER(title) LIKE ?', ['%'.mb_strtolower($data['search']).'%']);
        }
        return $query->select(['id','title','classification','status','critical','version','owner_id','reviewer_id','next_review_at','created_at','updated_at'])
            ->latest('id')->paginate(20);
    }

    public function show(Request $request, int $id)
    {
        Gate::authorize('itsmRead');
        $article = $this->visible(SupportKnowledgeArticle::query(), $request)->findOrFail($id);
        if (! in_array($this->role($request), ['admin','support_n3','support_manager'], true)) {
            abort_unless($article->status === 'published', 404);
        }
        return $article;
    }

    public function create(Request $request)
    {
        Gate::authorize('itsmManage');
        $data = $request->validate([
            'title' => 'required|string|max:180',
            'content' => 'required|string|min:10|max:30000',
            'classification' => ['required', Rule::in(['support','n3'])],
            'critical' => 'required|boolean',
            'next_review_at' => 'required|date|after:now',
        ]);
        abort_if($data['classification'] === 'n3' && ! in_array($this->role($request), ['admin','support_n3'], true), 403);
        return DB::transaction(function () use ($request, $data) {
            $article = SupportKnowledgeArticle::create([
                ...$data, 'owner_id' => $request->user()->id, 'status' => 'draft', 'version' => 1,
            ]);
            $this->revision($article, $request, 'created');
            return response()->json($article, 201);
        }, 3);
    }

    public function update(Request $request, int $id)
    {
        Gate::authorize('itsmManage');
        $data = $request->validate([
            'version' => 'required|integer|min:1',
            'title' => 'sometimes|required|string|max:180',
            'content' => 'sometimes|required|string|min:10|max:30000',
            'classification' => ['sometimes', Rule::in(['support','n3'])],
            'critical' => 'sometimes|boolean',
            'next_review_at' => 'sometimes|date|after:now',
        ]);
        return DB::transaction(function () use ($request, $id, $data) {
            $article = $this->visible(SupportKnowledgeArticle::query(), $request)->lockForUpdate()->findOrFail($id);
            abort_unless($article->version === (int) $data['version'], 409, 'Version obsolète.');
            abort_if($article->status === 'retired', 409, 'Article retiré.');
            if (($data['classification'] ?? null) === 'n3') {
                abort_unless(in_array($this->role($request), ['admin','support_n3'], true), 403);
            }
            unset($data['version']);
            $article->fill($data);
            $article->status = 'draft';
            $article->reviewer_id = null;
            $article->version++;
            $article->save();
            $this->revision($article, $request, 'updated');
            return $article;
        }, 3);
    }

    public function decision(Request $request, int $id)
    {
        Gate::authorize('itsmManage');
        $data = $request->validate([
            'version' => 'required|integer|min:1',
            'action' => ['required', Rule::in(['publish','retire'])],
        ]);
        return DB::transaction(function () use ($request, $id, $data) {
            $article = $this->visible(SupportKnowledgeArticle::query(), $request)->lockForUpdate()->findOrFail($id);
            abort_unless($article->version === (int) $data['version'], 409, 'Version obsolète.');
            if ($data['action'] === 'publish') {
                abort_unless($article->status === 'draft', 409, 'Publication impossible.');
                abort_if($article->critical && $article->owner_id === $request->user()->id, 409, 'Relecteur distinct requis.');
                $article->status = 'published';
                $article->reviewer_id = $request->user()->id;
            } else {
                abort_unless($article->status === 'published', 409, 'Retrait impossible.');
                $article->status = 'retired';
            }
            $article->version++;
            $article->save();
            $this->revision($article, $request, $data['action'] === 'publish' ? 'published' : 'retired');
            return $article;
        }, 3);
    }

    public function revisions(Request $request, int $id)
    {
        Gate::authorize('itsmRead');
        $article = $this->visible(SupportKnowledgeArticle::query(), $request)->findOrFail($id);
        abort_unless(in_array($this->role($request), ['admin','support_n3','support_manager'], true), 403);
        return DB::table('support_knowledge_revisions')
            ->where('company_id', app(TenantContext::class)->requireId())
            ->where('article_id', $article->id)
            ->orderByDesc('version')->paginate(30);
    }
}
