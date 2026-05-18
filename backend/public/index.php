<?php
declare(strict_types=1);

/**
 * MailPilot AI — Front Controller
 * Only entry point. Everything else lives above /public.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use MailPilot\Http\Router;
use MailPilot\Http\Kernel;

$config = require __DIR__ . '/../config/config.php';

$kernel = new Kernel($config);
$router = new Router($kernel);

// --- Route map ---
$router->get ('/api/v1/ping',                    'HealthController@ping');
$router->get ('/api/v1/health',                  'HealthController@health');

$router->post('/api/v1/auth/oauth/start',        'AuthController@oauthStart');
$router->get ('/api/v1/auth/oauth/callback',     'AuthController@oauthCallback');
$router->get ('/api/v1/auth/oauth/exchange',     'AuthController@oauthExchange');
$router->post('/api/v1/auth/refresh',            'AuthController@refresh');

$router->get ('/api/v1/briefing/today',          'BriefingController@today');

$router->get ('/api/v1/mails',                   'MailController@list');
$router->post('/api/v1/mails/bulk/{action}',     'MailController@bulkAction');
$router->post('/api/v1/mails/by-graph-id/{ms_message_id}/ensure-scored', 'MailController@ensureScored');
$router->post('/api/v1/mails/{id}/summarize',    'MailController@summarize');
$router->post('/api/v1/mails/{id}/draft-reply',  'MailController@draftReply');
$router->post('/api/v1/mails/{id}/rescore',      'MailController@rescore');
$router->post('/api/v1/mails/{id}/correct-score','MailController@correctScore');
$router->post('/api/v1/mails/{id}/done',          'MailController@markUserDone');
// Sprint 6f — Auto-Reply-Drafts
$router->get ('/api/v1/mails/{id}/drafts/active','MailController@getActiveDraft');
$router->post('/api/v1/drafts/{id}/dismiss',     'MailController@dismissDraft');

$router->post('/api/v1/sync',                    'SyncController@trigger');
$router->get ('/api/v1/sync/status/{id}',        'SyncController@status');

// Phase-2 Split: 6 fokussierte Controller statt einem 633-LOC-Monolith.
// API-URLs bleiben identisch — nur Handler-Strings zeigen auf neue Klassen.
$router->get ('/api/v1/settings/user',           'UserSettingsController@getUser');
$router->patch('/api/v1/settings/user',          'UserSettingsController@updateUser');
$router->get ('/api/v1/settings/vip',            'VipController@listVip');
$router->post('/api/v1/settings/vip',            'VipController@addVip');
$router->delete('/api/v1/settings/vip/{id}',     'VipController@deleteVip');
$router->get ('/api/v1/settings/redaction',      'RedactionController@listRedaction');
$router->post('/api/v1/settings/redaction',      'RedactionController@addRedaction');
$router->get ('/api/v1/settings/auto-sort',      'AutoSortController@listAutoSort');
$router->patch('/api/v1/settings/auto-sort',     'AutoSortController@updateAutoSort');
$router->post('/api/v1/settings/auto-sort/apply-now', 'AutoSortController@applyAutoSortNow');
$router->delete('/api/v1/settings/auto-sort/sub/{label}/{name}', 'AutoSortController@deleteAutoSortSub');
$router->post('/api/v1/settings/rescore-all',        'AutoSortController@rescoreAll');
$router->get   ('/api/v1/settings/sub-labels',       'SubLabelController@listSubLabels');
$router->post  ('/api/v1/settings/sub-labels',       'SubLabelController@addSubLabel');
$router->patch ('/api/v1/settings/sub-labels/{id}',  'SubLabelController@updateSubLabel');
$router->delete('/api/v1/settings/sub-labels/{id}',  'SubLabelController@deleteSubLabel');

$router->get ('/api/v1/me/export',               'MeController@export');
$router->delete('/api/v1/me',                    'MeController@delete');

$router->get ('/api/v1/me/profile',              'MeController@profile');
$router->post('/api/v1/me/aliases/scan',         'MeController@scanAliases');
$router->post('/api/v1/me/aliases',              'MeController@saveAliases');
$router->post('/api/v1/me/privacy-acknowledge',  'MeController@acknowledgePrivacy');

// Sprint 6c — Pending-Action-Queue
$router->get ('/api/v1/pending',                     'PendingController@list');
$router->post('/api/v1/pending/bulk-approve',        'PendingController@bulkApprove');
$router->post('/api/v1/pending/{id}/approve',        'PendingController@approve');
$router->post('/api/v1/pending/{id}/reject',         'PendingController@reject');

// Sprint 6c — Modus-Toggles (3 × 3)
$router->get ('/api/v1/settings/modes',              'ModesController@getModes');
$router->post('/api/v1/settings/modes',              'ModesController@saveModes');
// Sprint 6f — Auto-Reply-Backlog-Trigger
$router->post('/api/v1/settings/auto-reply/include-backlog', 'ModesController@includeAutoReplyBacklog');

// Sprint 6d — Reason-Capture für Move-Korrekturen (Privacy-gated)
$router->post('/api/v1/me/auto-sort-corrections/{id}/reason', 'MeController@setCorrectionReason');

// Sprint 6e — „MailPilot Heute"-Dashboard
$router->get ('/api/v1/today',                       'TodayController@today');
$router->post('/api/v1/mails/{id}/correct-owner',    'TodayController@correctOwner');

$router->dispatch();
