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
// Sprint 6f — Auto-Reply-Drafts
$router->get ('/api/v1/mails/{id}/drafts/active','MailController@getActiveDraft');
$router->post('/api/v1/drafts/{id}/dismiss',     'MailController@dismissDraft');

$router->post('/api/v1/sync',                    'SyncController@trigger');
$router->get ('/api/v1/sync/status/{id}',        'SyncController@status');

$router->get ('/api/v1/settings/user',           'SettingsController@getUser');
$router->patch('/api/v1/settings/user',          'SettingsController@updateUser');
$router->get ('/api/v1/settings/vip',            'SettingsController@listVip');
$router->post('/api/v1/settings/vip',            'SettingsController@addVip');
$router->delete('/api/v1/settings/vip/{id}',     'SettingsController@deleteVip');
$router->get ('/api/v1/settings/redaction',      'SettingsController@listRedaction');
$router->post('/api/v1/settings/redaction',      'SettingsController@addRedaction');
$router->get ('/api/v1/settings/auto-sort',      'SettingsController@listAutoSort');
$router->patch('/api/v1/settings/auto-sort',     'SettingsController@updateAutoSort');
$router->post('/api/v1/settings/auto-sort/apply-now', 'SettingsController@applyAutoSortNow');
$router->delete('/api/v1/settings/auto-sort/sub/{label}/{name}', 'SettingsController@deleteAutoSortSub');
$router->post('/api/v1/settings/rescore-all',        'SettingsController@rescoreAll');
$router->get   ('/api/v1/settings/sub-labels',       'SettingsController@listSubLabels');
$router->post  ('/api/v1/settings/sub-labels',       'SettingsController@addSubLabel');
$router->patch ('/api/v1/settings/sub-labels/{id}',  'SettingsController@updateSubLabel');
$router->delete('/api/v1/settings/sub-labels/{id}',  'SettingsController@deleteSubLabel');

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
$router->get ('/api/v1/settings/modes',              'SettingsController@getModes');
$router->post('/api/v1/settings/modes',              'SettingsController@saveModes');
// Sprint 6f — Auto-Reply-Backlog-Trigger
$router->post('/api/v1/settings/auto-reply/include-backlog', 'SettingsController@includeAutoReplyBacklog');

// Sprint 6d — Reason-Capture für Move-Korrekturen (Privacy-gated)
$router->post('/api/v1/me/auto-sort-corrections/{id}/reason', 'MeController@setCorrectionReason');

// Sprint 6e — „MailPilot Heute"-Dashboard
$router->get ('/api/v1/today',                       'TodayController@today');
$router->post('/api/v1/mails/{id}/correct-owner',    'TodayController@correctOwner');

$router->dispatch();
