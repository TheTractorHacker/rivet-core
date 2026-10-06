<?php

declare(strict_types=1);

namespace RivetCore\Webhooks;

/**
 * Catalog of webhook destination presets (free or open-source, mostly self-hostable platforms, plus generic options).
 * Data only: editions render the picker and store the choice; Core supplies the format, headers, URL shape, setup steps,
 * sample curl and signature-verification snippets. Platform details were written from the vendors' public documentation
 * and are deliberately hedged where they change often: every preset links its docs, and notes say "verify" where unsure.
 *
 * @api
 */
final class Destinations
{
    /** @var array<string,Destination>|null */
    private static ?array $all = null;

    /** @return list<Destination> in display order */
    public static function all(): array
    {
        return array_values(self::index());
    }

    public static function get(string $id): ?Destination
    {
        return self::index()[$id] ?? null;
    }

    public static function has(string $id): bool
    {
        return isset(self::index()[$id]);
    }

    /** @return array<string,list<Destination>> category => presets */
    public static function byCategory(): array
    {
        $out = [];
        foreach (self::all() as $d) {
            $out[$d->category][] = $d;
        }

        return $out;
    }

    /** @return array<string,string> category => label */
    public static function categoryLabels(): array
    {
        return ['automation' => 'Automation platforms', 'chat' => 'Team chat', 'notify' => 'Push and notification services', 'home' => 'Home automation', 'generic' => 'Generic'];
    }

    /** @return list<array<string,mixed>> */
    public static function toArray(): array
    {
        return array_map(static fn (Destination $d): array => $d->toArray(), self::all());
    }

    public static function toJson(): string
    {
        return (string) json_encode(['categories' => self::categoryLabels(), 'destinations' => self::toArray()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /** @return array<string,Destination> */
    private static function index(): array
    {
        if (self::$all === null) {
            self::$all = [];
            foreach (self::build() as $d) {
                self::$all[$d->id] = $d;
            }
        }

        return self::$all;
    }

    // ------------------------------------------------------------------------------------------------------------

    /** @return list<Destination> */
    private static function build(): array
    {
        $verifyAll = self::verifySnippets();
        $sig = 'Every request also carries X-Rivet-Timestamp and X-Rivet-Signature-V2 (HMAC-SHA256 of "<timestamp>.<body>"); see docs/webhooks.md.';
        $urlSecret = 'The URL is the secret: anyone who has it can post to the destination. It is stored encrypted and never shown again.';
        $internal = 'If the platform runs on your own LAN, add its network under Settings > Webhooks > Internal networks first; private addresses are blocked by default.';
        $tpl = '{"value1":"{{summary.title}}","value2":"{{summary.summary}}","value3":"{{summary.url|default:""}}"}';

        $d = [];

        // ---------------------------------------------------------------- automation platforms
        $d[] = self::make(
            'n8n', 'n8n', 'automation', 'Workflow automation (fair-code, self-hostable). Start a workflow from a Webhook node.', 'json', 'POST',
            'https://n8n.example.com/webhook/<path>', null, ['none', 'hmac', 'header', 'bearer', 'basic'], 'hmac', null, [], [],
            'https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.webhook/',
            [
                'In n8n create a workflow and add a Webhook node as the trigger.',
                'Set HTTP Method to POST (the default is GET) and choose a Path.',
                'Copy the Production URL (it contains /webhook/, not /webhook-test/) and paste it here.',
                'Optionally set Authentication on the node (Basic or Header) and mirror it in the Authentication section here.',
                'To verify our signature, enable the node option Raw Body, then use the Code-node snippet below.',
                'Activate the workflow. The Test URL (/webhook-test/) only works while you click "Listen for test event".',
            ],
            $verifyAll,
            [
                'Production URLs only work while the workflow is Active; the /webhook-test/ URL only accepts one call after you click "Listen for test event".',
                'Our JSON arrives under $json.body, headers under $json.headers.',
                'n8n parses JSON before your Code node runs; verifying the signature needs the Raw Body option, and the Code node needs NODE_FUNCTION_ALLOW_BUILTIN=crypto. Verify against the n8n docs for your version.',
                'n8n is "fair-code" (Sustainable Use License): source-available and free to self-host, not OSI open source.',
                $internal,
            ],
        );
        $d[] = self::make(
            'node-red', 'Node-RED', 'automation', 'Flow-based programming (open source). Receive the call with an "http in" node.', 'json', 'POST',
            'https://nodered.example.com/<path>', null, ['none', 'hmac', 'basic', 'bearer', 'header'], 'hmac', null, [], [],
            'https://nodered.org/docs/user-guide/nodes',
            [
                'Add an "http in" node, set Method to POST and a URL such as /rivet.',
                'Connect it to your flow and finish with an "http response" node (status 200); without it the sender waits until it times out.',
                'Deploy, then paste the full URL (https://<host>/rivet) here.',
                'Protect the endpoint: set httpNodeAuth in settings.js (basic) or check a header in a function node, and configure the same under Authentication here.',
                'Our JSON body is in msg.payload; headers are in msg.req.headers.',
            ],
            $verifyAll,
            [
                'Without an "http response" node Node-RED never answers and our attempt is logged as a timeout.',
                'The HTTP-in node does not keep the raw request body by default, so re-serialising msg.payload can differ from the signed bytes. Verify against the Node-RED docs for capturing the raw body before relying on signature checks.',
                'Node-RED is normally on a LAN or behind a reverse proxy; allow the network under Internal networks if needed.',
            ],
        );
        $d[] = self::make(
            'activepieces', 'Activepieces', 'automation', 'Open-source (MIT) automation. Start a flow with the Catch Webhook trigger.', 'json', 'POST',
            'https://activepieces.example.com/api/v1/webhooks/<flow-id>', null, ['none', 'hmac', 'header', 'bearer'], 'hmac', null, [], [],
            'https://www.activepieces.com/docs/trigger/webhook',
            [
                'Create a flow and choose the Webhook (Catch Webhook) trigger.',
                'Copy the webhook URL shown in the trigger. Use the test URL (ends in /test) while building, the live URL once the flow is published.',
                'Paste the live URL here and send a test event; Activepieces then shows the sample data for the next steps.',
                'Publish the flow.',
            ],
            $verifyAll,
            [
                'The URL shape above is typical; verify against the Activepieces docs for your version.',
                'The test URL is only used for sample capture; use the published URL for real delivery.',
                $internal,
            ],
        );
        $d[] = self::make(
            'windmill', 'Windmill', 'automation', 'Open-source (AGPL) scripts, flows and apps. Trigger a script or flow by its webhook endpoint.', 'json', 'POST',
            'https://windmill.example.com/api/w/<workspace>/jobs/run/p/<script-path>', null, ['bearer', 'header'], 'bearer', null, [], [],
            'https://www.windmill.dev/docs/core_concepts/webhooks',
            [
                'In Windmill open the script or flow and choose Triggers > Webhooks (or copy the URL pattern above).',
                'Create a user token (Account settings > Tokens) and enter it here under Authentication > Bearer.',
                'Make the script accept the arguments event (string), timestamp (string) and data (object); Windmill maps JSON body keys to script arguments by name.',
                'Paste the run-by-path URL (jobs/run/p/...) here. Use jobs/run_wait_result/p/... only if you need the result; the default is asynchronous.',
            ],
            $verifyAll,
            [
                'Windmill maps JSON keys to script arguments: a script without event/timestamp/data parameters will reject the call. Verify against the Windmill docs for your version.',
                'A bearer token is required; webhooks also accept a token query parameter, but a header keeps it out of logs.',
                $internal,
            ],
        );
        $d[] = self::make(
            'huginn', 'Huginn', 'automation', 'Open-source (MIT) agents that watch and act. Receive events with a Webhook Agent.', 'json', 'POST',
            'https://huginn.example.com/users/{user_id}/web_requests/{agent_id}/{secret}', null, ['none', 'hmac'], 'none', null,
            [
                new DestinationField('user_id', 'Huginn user id', 'text', true, 'The number in the URL Huginn shows for the agent.', 'url', '', '1'),
                new DestinationField('agent_id', 'Agent id', 'text', true, 'The Webhook Agent id.', 'url', '', '23'),
                new DestinationField('secret', 'Agent secret', 'secret', true, 'The "secret" option of the Webhook Agent.', 'url', '', 'a-long-random-secret'),
            ],
            [],
            'https://github.com/huginn/huginn/wiki/Agent-Webhook-Agent',
            [
                'Create a Webhook Agent. Set "secret" to a long random value and "verbs" to include post.',
                'Set expected_receive_period_in_days (required; Huginn flags the agent as stale otherwise).',
                'The agent URL is https://<huginn>/users/<user_id>/web_requests/<agent_id>/<secret>; enter its three parts here.',
                'Add an agent that consumes the events (for example a Trigger Agent filtering on event).',
            ],
            [],
            [
                'Huginn authenticates by the secret in the URL path only; there is no header authentication, so use HTTPS.',
                'By default only the verbs listed in the agent options are accepted: make sure post is included. Verify against the agent\'s built-in description.',
                'The JSON body becomes the event payload (see the agent\'s payload_path option).',
            ],
        );
        $d[] = self::make(
            'home-assistant', 'Home Assistant', 'home', 'Open-source home automation. Fire an automation with a webhook trigger.', 'json', 'POST',
            'https://homeassistant.example.com/api/webhook/<webhook_id>', '#^https?://\S+/api/webhook/[A-Za-z0-9_-]+$#', ['none', 'hmac'], 'none', null, [], [],
            'https://www.home-assistant.io/docs/automation/trigger/#webhook-trigger',
            [
                'In Home Assistant create an automation with the trigger type Webhook.',
                'Copy the webhook ID (a long random id is its only protection) and build the URL https://<your-ha>/api/webhook/<webhook_id>.',
                'Under the trigger options keep POST (and PUT) allowed. Our body is JSON, available as trigger.json.',
                'Untick "Only accessible from the local network" unless the sender is on the same network (default is local only).',
            ],
            [],
            [
                'The webhook ID is the only secret; use a long random one and HTTPS (Nabu Casa or your reverse proxy).',
                'The trigger option "local_only" defaults to true: requests from other networks are rejected. Home Assistant normally sits on a private LAN, so you also need it under Internal networks here.',
                'JSON bodies are available as trigger.json, form bodies as trigger.data.',
            ],
        );
        $d[] = self::make(
            'apprise', 'Apprise API', 'notify', 'Open-source (BSD) notification gateway that fans out to 100+ services.', 'apprise', 'POST',
            'https://apprise.example.com/notify/{key}', null, ['none', 'hmac', 'basic', 'bearer', 'header'], 'none', null,
            [
                new DestinationField('key', 'Configuration key', 'text', true, 'The key of a saved Apprise configuration (stateful mode).', 'url', '', 'rivet'),
                new DestinationField('tag', 'Tag', 'text', false, 'Only notify the services with this tag (comma separated for OR).', 'option', 'apprise_tag', 'alerts'),
            ],
            [],
            'https://github.com/caronc/apprise-api',
            [
                'Run the Apprise API container and open its web UI.',
                'Create a configuration with your service URLs (Slack, e-mail, Telegram, ...) and note its key.',
                'Use https://<apprise>/notify/<key> as the URL; set a tag to target some of the services.',
                'Apprise API has no authentication of its own: put it behind a reverse proxy with basic auth and set the same under Authentication.',
            ],
            [],
            [
                'We send {title, body, type, format:"text"} to /notify/<key>. type maps severity to info, warning or failure.',
                'Apprise API itself is unauthenticated by default; do not expose it to the internet without a proxy.',
                $internal,
            ],
        );
        $d[] = self::make(
            'ntfy', 'ntfy', 'notify', 'Open-source (Apache/GPL) push notifications to phone and desktop. Public ntfy.sh or self-hosted.', 'ntfy', 'POST',
            'https://ntfy.sh/{topic}', null, ['none', 'bearer', 'basic'], 'none', null,
            [
                new DestinationField('topic', 'Topic', 'text', true, 'Anyone who knows a topic name on a public server can read it: use a long random name or access control.', 'url', '', 'rivetit-alerts-7f3k2q9x'),
                new DestinationField('priority', 'Priority (1-5)', 'number', false, 'Leave empty to derive it from severity (3 info, 4 warning, 5 critical).', 'option', 'ntfy_priority', '3'),
                new DestinationField('tags', 'Tags', 'text', false, 'Comma-separated tags or emoji short codes added to every message.', 'option', 'ntfy_tags', 'rivetit,msp'),
            ],
            [],
            'https://docs.ntfy.sh/publish/',
            [
                'Choose a topic name (long and random on the public server) and subscribe to it in the ntfy app.',
                'URL: https://ntfy.sh/<topic>, or your self-hosted server.',
                'For a protected topic create an access token (ntfy token add) and enter it under Authentication > Bearer.',
                'Send a test; the message title, priority and tags come from the event.',
            ],
            [],
            [
                'The body is the message text; Title, Priority, Tags and Click are sent as headers. Non-ASCII titles are sent as RFC 2047 encoded words; verify against the ntfy docs for your server version.',
                'The message limit is 4096 bytes; we truncate.',
                'ntfy.sh rate-limits publishers per IP (defaults are a burst of about 60 requests, then roughly one per 5 seconds); self-hosted limits are configurable. Verify against the ntfy docs.',
                'A public topic name is effectively a password.',
            ],
        );
        $d[] = self::make(
            'gotify', 'Gotify', 'notify', 'Open-source (MIT) self-hosted push server.', 'gotify', 'POST',
            'https://gotify.example.com/message', null, ['header', 'none'], 'header', 'X-Gotify-Key', [], [],
            'https://gotify.net/docs/pushmsg',
            [
                'In Gotify create an Application and copy its token.',
                'URL: https://<gotify>/message',
                'Authentication: Header, name X-Gotify-Key, value the application token (this keeps the token out of the URL).',
                'Send a test message.',
            ],
            [],
            [
                'The application token may also be passed as ?token=, but a header keeps it out of access logs.',
                'We send {title, message, priority, extras}; priority is derived from severity (3, 6, 9).',
                $internal,
            ],
        );

        // ---------------------------------------------------------------- team chat
        $d[] = self::make(
            'discord', 'Discord', 'chat', 'Post to a channel with an incoming webhook (free).', 'discord', 'POST',
            'https://discord.com/api/webhooks/<id>/<token>', '#^https://(?:(?:ptb|canary)\.)?discord(?:app)?\.com/api(?:/v\d+)?/webhooks/\d+/[A-Za-z0-9_-]+/?(?:\?.*)?$#', ['none'], 'none', null,
            [new DestinationField('username', 'Display name', 'text', false, 'Overrides the webhook\'s default name for these messages.', 'option', 'username', 'RivetIT')],
            [],
            'https://discord.com/developers/docs/resources/webhook#execute-webhook',
            [
                'Channel settings > Integrations > Webhooks > New Webhook, pick the channel.',
                'Copy the Webhook URL and paste it here.',
                'Send a test: the message arrives as an embed with a coloured bar for severity.',
            ],
            [],
            [
                $urlSecret,
                'Limits: 2000 characters of content, embed description 4096, 25 fields, 6000 characters per embed; we truncate to fit.',
                'Discord rate-limits each webhook (about 5 requests per 2 seconds) and answers 429 with Retry-After; bursts of events can be delayed or dropped after retries.',
                'Mentions are disabled (allowed_mentions) so event text cannot ping @everyone.',
                'Success is HTTP 204 with an empty body.',
            ],
        );
        $d[] = self::make(
            'mattermost', 'Mattermost', 'chat', 'Open-source (MIT/AGPL) team chat. Incoming webhook.', 'slack_attachments', 'POST',
            'https://mattermost.example.com/hooks/<token>', '#^https?://\S+/hooks/[A-Za-z0-9]+/?$#', ['none'], 'none', null, [], [],
            'https://developers.mattermost.com/integrate/webhooks/incoming/',
            [
                'Main menu > Integrations > Incoming Webhooks > Add Incoming Webhook (an admin may need to enable incoming webhooks first).',
                'Choose the channel and save; copy the URL (https://<host>/hooks/<token>).',
                'Paste it here and send a test.',
            ],
            [],
            [
                $urlSecret,
                'Mattermost renders Slack-style attachments (we send those), not Slack Block Kit.',
                'Overriding the channel or username from the payload needs "Enable integrations to override usernames/channels" in System Console; we do not send them. Verify against the Mattermost docs.',
                $internal,
            ],
        );
        $d[] = self::make(
            'rocketchat', 'Rocket.Chat', 'chat', 'Open-source (MIT) team chat. Incoming webhook integration.', 'slack_attachments', 'POST',
            'https://chat.example.com/hooks/<id>/<token>', '#^https?://\S+/hooks/[^/\s]+/[^/\s]+/?$#', ['none'], 'none', null, [], [],
            'https://docs.rocket.chat/docs/incoming-webhook-script',
            [
                'Administration > Workspace > Integrations > New > Incoming.',
                'Enable it, choose the channel and a posting user, and save.',
                'Copy the Webhook URL (https://<host>/hooks/<id>/<token>) and paste it here.',
            ],
            [],
            [
                $urlSecret,
                'Rocket.Chat accepts Slack-compatible text and attachments (what we send). Verify the field support against the Rocket.Chat docs for your version.',
                $internal,
            ],
        );
        $d[] = self::make(
            'slack', 'Slack', 'chat', 'Slack incoming webhook (free workspaces included).', 'slack', 'POST',
            'https://hooks.slack.com/services/T000/B000/XXXX', '#^https://hooks\.slack\.com/services/[A-Za-z0-9]+/[A-Za-z0-9]+/[A-Za-z0-9]+$#', ['none'], 'none', null, [], [],
            'https://api.slack.com/messaging/webhooks',
            [
                'Create a Slack app (api.slack.com/apps) and enable Incoming Webhooks.',
                'Add New Webhook to Workspace, choose the channel, and copy the URL.',
                'Paste it here and send a test.',
            ],
            [],
            [
                $urlSecret,
                'A webhook posts to the one channel chosen when it was created; the payload cannot override it for new apps.',
                'Slack allows roughly one message per second per webhook; limits: header 150 characters, section text 3000, up to 50 blocks.',
                'Errors come back as short plain text (invalid_payload, no_service, channel_is_archived); a retired or revoked URL answers 404/410.',
                'Workflow Builder "webhook triggers" (hooks.slack.com/triggers/...) expect flat variables instead; use the Custom template destination for those.',
            ],
        );
        $d[] = self::make(
            'teams', 'Microsoft Teams', 'chat', 'Teams channel through a Workflows (Power Automate) webhook.', 'teams', 'POST',
            'https://prod-00.westus.logic.azure.com:443/workflows/<id>/triggers/manual/paths/invoke?api-version=2016-06-01&sp=...&sv=1.0&sig=...',
            '#^https://[A-Za-z0-9.-]+\.(?:logic\.azure\.com|powerplatform\.com)(?::\d+)?/\S+$#', ['none'], 'none', null, [], [],
            'https://learn.microsoft.com/en-us/microsoftteams/platform/webhooks-and-connectors/how-to/add-incoming-webhook',
            [
                'In Teams open the channel > ... > Workflows and pick "Post to a channel when a webhook request is received".',
                'Finish the wizard and copy the HTTP POST URL it shows.',
                'Paste the URL here and send a test; the message arrives as an Adaptive Card.',
            ],
            [],
            [
                $urlSecret,
                'The classic Office 365 Connector webhooks (outlook.office.com/webhook) are being retired by Microsoft; use the Workflows webhook.',
                'Messages are limited to about 28 KB; Teams throttles heavy posting per connector. Verify the current limits against the Microsoft docs.',
                'The signature in the URL (sig=) is part of the secret.',
                'Creating the Workflow needs a Microsoft 365 licence that includes Power Automate Workflows.',
            ],
        );
        $d[] = self::make(
            'matrix-hookshot', 'Matrix (hookshot)', 'chat', 'Open-source Matrix bridge with generic inbound webhooks.', 'matrix_hookshot', 'POST',
            'https://hookshot.example.com/webhook/<uuid>', '#^https?://\S+/webhook/[A-Za-z0-9-]+/?$#', ['none'], 'none', null,
            [new DestinationField('username', 'Display name', 'text', false, 'Name shown for the bridged message.', 'option', 'username', 'RivetIT')],
            [],
            'https://matrix-org.github.io/matrix-hookshot/latest/setup/webhooks.html',
            [
                'Install matrix-hookshot with the generic webhooks feature enabled.',
                'In the room send: !hookshot webhook rivetit  (hookshot replies with the URL in a private message).',
                'Paste that URL here and send a test.',
            ],
            [],
            [
                $urlSecret,
                'We send {text, html, username}, which the default hookshot transformation understands. Verify against the hookshot docs for your version.',
                $internal,
            ],
        );
        $d[] = self::make(
            'matrix-client', 'Matrix (client API)', 'chat', 'Send into a room directly with the Matrix client-server API and a bot access token.', 'matrix', 'PUT',
            'https://matrix.example.org/_matrix/client/v3/rooms/{room_id}/send/m.room.message/{txn}', '#^https?://\S+/_matrix/client/v\d+/rooms/\S+/send/m\.room\.message/\S+$#', ['bearer'], 'bearer', null,
            [new DestinationField('room_id', 'Room ID', 'text', true, 'Internal room id such as !abc123:example.org (not the alias). The bot must be joined to the room.', 'url', '', '!abc123:example.org')],
            [],
            'https://spec.matrix.org/latest/client-server-api/#put_matrixclientv3roomsroomidsendeventtypetxnid',
            [
                'Create a bot user, join it to the room, and obtain an access token for it.',
                'Find the room id (Room settings > Advanced).',
                'Enter the room id here; keep {txn} in the URL: it is replaced by a unique transaction id per message.',
                'Authentication: Bearer with the bot access token.',
            ],
            [],
            [
                'Matrix de-duplicates PUTs by transaction id, so the URL must end in a unique id: keep the {txn} placeholder (we fill it from the message, so retries stay idempotent).',
                'The room id contains ! and : is percent-encoded automatically.',
                'Server rate limits (429 with retry_after_ms) depend on the homeserver. Verify against your homeserver docs.',
                $internal,
            ],
        );
        $d[] = self::make(
            'telegram', 'Telegram', 'chat', 'Telegram Bot API sendMessage to a chat, group or channel.', 'telegram', 'POST',
            'https://api.telegram.org/bot{bot_token}/sendMessage', '#^https://api\.telegram\.org/bot\d+:[A-Za-z0-9_-]+/sendMessage$#', ['none'], 'none', null,
            [
                new DestinationField('bot_token', 'Bot token', 'secret', true, 'From @BotFather, like 123456:ABC-DEF.', 'url', '', '123456:ABC-DEF1234ghIkl'),
                new DestinationField('chat_id', 'Chat ID', 'text', true, 'Numeric id (negative for groups and channels) or @channelname. The bot must be a member.', 'option', 'chat_id', '-1001234567890'),
            ],
            [],
            'https://core.telegram.org/bots/api#sendmessage',
            [
                'Talk to @BotFather, run /newbot and copy the bot token.',
                'Add the bot to your group or channel (channels: as an administrator who may post).',
                'Get the chat id: send a message in the chat, then open https://api.telegram.org/bot<token>/getUpdates and read chat.id.',
                'Enter the token and chat id here; the URL is built for you.',
            ],
            [],
            [
                'The bot token is part of the URL and is a secret.',
                'Messages are limited to 4096 characters and use HTML parse mode (all text is escaped).',
                'Telegram limits bots to about 1 message per second per chat, 20 per minute to the same group, and roughly 30 per second overall; extra requests get HTTP 429 with retry_after.',
            ],
        );

        // ---------------------------------------------------------------- hosted automation (free tiers)
        $d[] = self::make(
            'zapier', 'Zapier (Catch Hook)', 'automation', 'Start a Zap with "Webhooks by Zapier" > Catch Hook.', 'json', 'POST',
            'https://hooks.zapier.com/hooks/catch/<account>/<hook>/', '#^https://hooks\.zapier\.com/hooks/catch/\d+/[A-Za-z0-9]+/?$#', ['none'], 'none', null, [], [],
            'https://help.zapier.com/hc/en-us/articles/8496288690317-Trigger-Zaps-from-webhooks',
            [
                'Create a Zap with the trigger Webhooks by Zapier > Catch Hook.',
                'Copy the Custom Webhook URL and paste it here.',
                'Send a test event, then use "Test trigger" in Zapier to load it.',
            ],
            [],
            [
                $urlSecret,
                '"Webhooks by Zapier" is a premium app: check that your plan includes it. Verify against the Zapier docs.',
                'Zapier cannot verify our signature (no access to the raw body); the URL is the secret.',
                'Payload and rate limits apply per plan; verify against the Zapier docs.',
            ],
        );
        $d[] = self::make(
            'make', 'Make (Integromat)', 'automation', 'Start a scenario with a Custom webhook module.', 'json', 'POST',
            'https://hook.eu1.make.com/<token>', '#^https://hook\.[A-Za-z0-9.-]*(?:make|integromat)\.com/[A-Za-z0-9]+/?$#', ['none', 'header'], 'none', null, [], [],
            'https://www.make.com/en/help/tools/webhooks',
            [
                'Create a scenario and add Webhooks > Custom webhook as the first module.',
                'Add a webhook, copy its address and paste it here.',
                'Click "Run once", then send a test event so Make learns the data structure.',
                'Turn scheduling on so the scenario runs when events arrive.',
            ],
            [],
            [
                $urlSecret,
                'The scenario must be listening ("Run once") or scheduled; otherwise Make answers with an error.',
                'Make can require an additional header ("Add IP/ header restrictions"): configure it under Authentication > Header if you enable that.',
                'Plan limits on operations and webhook queue size apply; verify against the Make docs.',
            ],
        );
        $d[] = self::make(
            'pipedream', 'Pipedream', 'automation', 'Free-tier workflows with an HTTP / Webhook trigger.', 'json', 'POST',
            'https://eoxxxxxxxxxxxxx.m.pipedream.net', '#^https://[A-Za-z0-9]+\.m\.pipedream\.net(?:/\S*)?$#', ['none', 'hmac', 'header', 'bearer'], 'hmac', null, [], [],
            'https://pipedream.com/docs/workflows/building-workflows/triggers/#http',
            [
                'Create a workflow and choose the trigger "New HTTP / Webhook Requests".',
                'Copy the unique URL and paste it here.',
                'Send a test event: it appears as the trigger event (body under event.body, headers under event.headers).',
                'Deploy the workflow.',
            ],
            $verifyAll,
            [
                $urlSecret,
                'The trigger can be set to require a custom response; leave the default (immediate 200).',
                'Payload size and request-rate limits depend on the plan; verify against the Pipedream docs.',
                'The trigger can expose the raw body for signature checks (a setting on the trigger); verify against the Pipedream docs.',
            ],
        );
        $d[] = self::make(
            'ifttt', 'IFTTT Webhooks', 'automation', 'Trigger an applet with the Webhooks service (event name + key).', 'template', 'POST',
            'https://maker.ifttt.com/trigger/<event>/with/key/<key>', '#^https://maker\.ifttt\.com/trigger/[A-Za-z0-9_-]+/(?:with/key|json/with/key)/[A-Za-z0-9_-]+/?$#', ['none'], 'none', null, [], [],
            'https://ifttt.com/maker_webhooks',
            [
                'Connect the Webhooks service in IFTTT and open its Documentation page to find your key.',
                'Create an applet: If "Receive a web request" with an event name, Then your action.',
                'URL: https://maker.ifttt.com/trigger/<event>/with/key/<key>; paste it here.',
                'Use value1, value2 and value3 in the action; we fill them with title, summary and link.',
            ],
            [],
            [
                $urlSecret,
                'IFTTT only passes three values (value1, value2, value3) to the action; we send title, summary and link. Use /json/with/key/ in the URL and the Generic JSON destination to send the whole envelope instead.',
                'Free accounts are limited to a small number of applets; verify against the IFTTT pricing and docs.',
            ],
            ['template' => $tpl, 'template_encoding' => 'json'],
        );

        // ---------------------------------------------------------------- generic
        $d[] = self::make(
            'generic-json', 'Generic JSON', 'generic', 'POST the standard RivetIT envelope {event, timestamp, data} as JSON to any endpoint.', 'json', 'POST',
            'https://example.com/webhooks/rivetit', null, ['none', 'hmac', 'bearer', 'basic', 'header'], 'hmac', null, [], [],
            'docs/webhooks.md',
            [
                'Create an HTTPS endpoint that accepts POST with a JSON body.',
                'Paste its URL here; choose how it authenticates us (bearer, basic or a header) or rely on our signature.',
                'Verify X-Rivet-Signature-V2 on your side (snippets below) and answer 2xx quickly.',
            ],
            $verifyAll,
            ['Any 2xx counts as delivered; other statuses and timeouts are retried.', $internal],
        );
        $d[] = self::make(
            'generic-form', 'Generic form-encoded', 'generic', 'POST application/x-www-form-urlencoded with flattened keys (event, timestamp, data.ticket_id, ...).', 'form', 'POST',
            'https://example.com/webhooks/rivetit', null, ['none', 'hmac', 'bearer', 'basic', 'header'], 'hmac', null, [], [],
            'docs/webhooks.md',
            [
                'Create an endpoint that accepts form posts.',
                'Paste its URL here.',
                'Read fields such as data.ticket_id; secret-looking keys are replaced with [redacted].',
            ],
            $verifyAll,
            ['PHP replaces dots in form field names with underscores ($_POST[\'data_ticket_id\']); read the raw body if you need the exact keys.', $internal],
        );
        $d[] = self::make(
            'custom-template', 'Custom template', 'generic', 'Write the body yourself with {{placeholders}}; for any API not listed.', 'template', 'POST',
            'https://example.com/hook', null, ['none', 'hmac', 'bearer', 'basic', 'header'], 'hmac', null, [], [],
            'docs/webhooks.md',
            [
                'Paste the endpoint URL and choose the authentication it needs.',
                'Write the body template; use {{summary.title}}, {{summary.summary}}, {{data.ticket_number}} and filters such as |truncate:80.',
                'Use the preview with sample data; invalid templates are rejected on save.',
            ],
            $verifyAll,
            ['Plain {{path}} is escaped for the chosen encoding; use {{path|json}} (no quotes) to insert numbers, lists or objects in JSON.', 'Templates are limited to 8 KB (64 KB rendered, 100 placeholders) and cannot run code.', $internal],
            ['template' => '{"title":"{{summary.title}}","text":"{{summary.summary}}","severity":"{{summary.severity}}","ticket":{{data.ticket_id|json}}}', 'template_encoding' => 'json'],
        );

        return $d;
    }

    /**
     * @param list<string> $authModes
     * @param list<DestinationField> $fields
     * @param array<string,string> $headers
     * @param list<string> $steps
     * @param array<string,string> $verify
     * @param list<string> $notes
     * @param array<string,mixed> $formatOptions
     */
    private static function make(
        string $id, string $name, string $category, string $description, string $format, string $method,
        string $urlHint, ?string $urlPattern, array $authModes, string $defaultAuth, ?string $authHeader,
        array $fields, array $headers, string $docsUrl, array $steps, array $verify, array $notes, array $formatOptions = [],
    ): Destination {
        $d = new Destination($id, $name, $category, $description, $format, $method, $urlHint, $urlPattern, $authModes, $defaultAuth, $authHeader, $fields, $headers, $docsUrl, $steps, '', $verify, $notes, $formatOptions);

        return new Destination($id, $name, $category, $description, $format, $method, $urlHint, $urlPattern, $authModes, $defaultAuth, $authHeader, $fields, $headers, $docsUrl, $steps, self::sampleCurl($d), $verify, $notes, $formatOptions);
    }

    private static function sampleCurl(Destination $d): string
    {
        $options = $d->formatOptions;
        foreach ($d->extraFields as $f) {
            if ($f->target === 'option' && $f->option !== '' && $f->example !== '' && $f->required) {
                $options[$f->option] = $f->example;
            }
        }
        $sample = PayloadTemplate::sampleContext('ticket.created');
        $p = PayloadFormatter::format($d->format, ['event' => 'ticket.created', 'timestamp' => $sample['timestamp'], 'data' => $sample['data']], $options + ['app_name' => 'RivetIT', 'link_url' => 'https://helpdesk.example.com/ticket/1042']);
        $q = static fn (string $s): string => "'" . str_replace("'", "'\\''", $s) . "'";
        $lines = ['curl -sS -X ' . $d->method . ' ' . $q($d->urlHint)];
        $lines[] = '  -H ' . $q('Content-Type: ' . $p->contentType);
        foreach ($d->headers + $p->headers as $k => $v) {
            $lines[] = '  -H ' . $q($k . ': ' . $v);
        }
        $lines[] = match ($d->defaultAuth) {
            'bearer' => '  -H ' . $q('Authorization: Bearer <token>'),
            'basic' => '  -u ' . $q('<user>:<password>'),
            'header' => '  -H ' . $q(($d->defaultAuthHeader ?? 'X-Api-Key') . ': <value>'),
            default => '',
        };
        $lines[] = '  --data-binary ' . $q($p->body);

        return implode(" \\\n", array_values(array_filter($lines, static fn (string $l): bool => $l !== '')));
    }

    /** @return array<string,string> */
    private static function verifySnippets(): array
    {
        return [
            'node' => <<<'JS'
const crypto = require('crypto');

// rawBody: the request body exactly as received (Buffer or string), NOT re-serialised JSON.
function verifyRivetSignature(rawBody, headers, secret, toleranceSeconds = 300) {
  const header = headers['x-rivet-signature-v2'] || '';
  const m = /^t=(\d+),v1=([0-9a-f]{64})$/.exec(header);
  if (!m) return false;
  if (Math.abs(Date.now() / 1000 - Number(m[1])) > toleranceSeconds) return false; // replay protection
  const signed = Buffer.concat([Buffer.from(m[1] + '.'), Buffer.from(rawBody)]);
  const expected = crypto.createHmac('sha256', secret).update(signed).digest();
  const got = Buffer.from(m[2], 'hex');
  return got.length === expected.length && crypto.timingSafeEqual(got, expected);
}
JS,
            'python' => <<<'PY'
import hashlib, hmac, re, time

def verify_rivet_signature(raw_body: bytes, headers: dict, secret: str, tolerance: int = 300) -> bool:
    """raw_body: the request body exactly as received. headers: lower-cased header names."""
    m = re.fullmatch(r"t=(\d+),v1=([0-9a-f]{64})", headers.get("x-rivet-signature-v2", ""))
    if not m:
        return False
    if abs(time.time() - int(m.group(1))) > tolerance:  # replay protection
        return False
    expected = hmac.new(secret.encode(), m.group(1).encode() + b"." + raw_body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, m.group(2))
PY,
            'php' => <<<'PHP'
function verifyRivetSignature(string $rawBody, string $header, string $secret, int $tolerance = 300): bool
{
    if (!preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $header, $m)) {
        return false;
    }
    if (abs(time() - (int) $m[1]) > $tolerance) { // replay protection
        return false;
    }

    return hash_equals(hash_hmac('sha256', $m[1] . '.' . $rawBody, $secret), $m[2]);
}

// $ok = verifyRivetSignature(file_get_contents('php://input'), $_SERVER['HTTP_X_RIVET_SIGNATURE_V2'] ?? '', $secret);
PHP,
            'bash' => <<<'SH'
#!/usr/bin/env bash
# usage: verify.sh '<X-Rivet-Signature-V2 header value>' body.bin '<secret>'
sig="$1"; file="$2"; secret="$3"
ts=$(sed -n 's/^t=\([0-9][0-9]*\),v1=[0-9a-f]\{64\}$/\1/p' <<<"$sig")
v1=$(sed -n 's/^t=[0-9]*,v1=\([0-9a-f]\{64\}\)$/\1/p' <<<"$sig")
[ -n "$ts" ] && [ -n "$v1" ] || { echo "malformed signature"; exit 1; }
age=$(( $(date +%s) - ts )); [ "${age#-}" -le 300 ] || { echo "stale timestamp"; exit 1; }   # replay protection
expected=$( { printf '%s.' "$ts"; cat "$file"; } | openssl dgst -sha256 -hmac "$secret" -hex | sed 's/^.* //')
[ "$expected" = "$v1" ] && echo ok || { echo "signature mismatch"; exit 1; }
SH,
            'n8n-code' => <<<'JS'
// n8n Code node ("Run Once for All Items"), placed right after the Webhook node.
// Needs: Webhook node > Options > Raw Body (on), and NODE_FUNCTION_ALLOW_BUILTIN=crypto on the n8n server.
const crypto = require('crypto');
const secret = $env.RIVET_WEBHOOK_SECRET; // or paste it here; env access may need N8N_BLOCK_ENV_ACCESS_IN_NODE=false

const item = $input.first();
const header = item.json.headers['x-rivet-signature-v2'] || '';
const m = /^t=(\d+),v1=([0-9a-f]{64})$/.exec(header);
if (!m || Math.abs(Date.now() / 1000 - Number(m[1])) > 300) throw new Error('Rejected: bad or stale signature header');

const raw = await this.helpers.getBinaryDataBuffer(0, 'data'); // the raw request body
const expected = crypto.createHmac('sha256', secret)
  .update(Buffer.concat([Buffer.from(m[1] + '.'), raw])).digest();
const got = Buffer.from(m[2], 'hex');
if (got.length !== expected.length || !crypto.timingSafeEqual(got, expected)) throw new Error('Rejected: signature mismatch');

return $input.all();
JS,
        ];
    }
}
