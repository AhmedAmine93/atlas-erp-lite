<?php

return ['seller' => ['name' => 'ATLAS Démo SARL', 'address' => '12 rue des Ateliers, 69002 Lyon', 'email' => 'contact@atlas.example'],
    'demo_password' => env('DEMO_PASSWORD'), 'metrics_token' => env('METRICS_TOKEN'),
    // Fail-closed human-approved M16 actions: require explicit operator opt-in.
    'ai_execution_enabled' => env('ATLAS_AI_EXECUTION_ENABLED', false)];
