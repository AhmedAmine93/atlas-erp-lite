<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * P071.01/.04 — declarative form preview contract, not an executable
 * template, SQL query language, workflow, or arbitrary HTML renderer.
 */
final class FormSchemaController extends Controller
{
    public function validateDraft(Request $request)
    {
        Gate::authorize('admin');

        $data = $request->validate([
            'version' => ['required', 'integer', 'in:1'],
            'name' => ['required', 'string', 'max:100', 'regex:/^[\pL\pN _-]+$/u'],
            'fields' => ['required', 'array', 'min:1', 'max:25'],
            'fields.*' => ['required', 'array:key,label,type,required,options'],
            'fields.*.key' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'fields.*.label' => ['required', 'string', 'max:100'],
            'fields.*.type' => ['required', 'string', Rule::in(['text', 'email', 'number', 'date', 'select', 'checkbox'])],
            'fields.*.required' => ['required', 'boolean'],
            'fields.*.options' => ['sometimes', 'array', 'max:20'],
            'fields.*.options.*' => ['string', 'max:80', 'distinct'],
        ]);

        foreach ($data['fields'] as $field) {
            if ($field['type'] === 'select') {
                if (empty($field['options'])) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'fields' => 'Une liste déroulante doit déclarer ses options.',
                    ]);
                }
            } elseif (! empty($field['options'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'fields' => 'Les options ne sont autorisées que pour les listes déroulantes.',
                ]);
            }
        }

        // A validated representation for a preview. No storage, no dynamic
        // model writes, no JavaScript, expressions, file uploads or SQL.
        return response()->json(['schema' => [
            'version' => 1,
            'name' => $data['name'],
            'fields' => array_map(static fn ($f) => [
                'key' => $f['key'],
                'label' => $f['label'],
                'type' => $f['type'],
                'required' => (bool) $f['required'],
                'options' => $f['type'] === 'select' ? array_values($f['options']) : [],
            ], $data['fields']),
        ]]);
    }
}
