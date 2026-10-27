<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Presentation;

/**
 * The remote-access part of the device header: which Mesh views to offer, which one the button leads with, and one plain sentence for the state. Pure: the caller
 * ({@see \RivetCore\Rmm\Remote\RemoteActions::headerRemote()}) gathers the facts, this class only decides.
 *
 * State, first match wins: `disabled` (Mesh switched off) -> `not_configured` (on, but no address or login key) -> `unmapped_agent_installed` (no node yet, the device
 * reported an installed Mesh agent) -> `unmapped` (no node, no agent reported) -> `offline` (mapped, the RivetIT agent is not checking in) -> `ready`.
 *
 * Views: desktop needs rmm.remote.launch; terminal and files need rmm.remote.manage and the remote-tools switch. A view is `available` only in state `ready` or
 * `offline` and when the caller may open it; otherwise `reason` says why. Primary view: Windows leads with desktop (terminal when the agent says there is no desktop
 * session, a Server Core install); Linux and everything else lead with terminal, except desktop when the agent reports a desktop session (and not a server chassis).
 * A primary that is not available falls back to the other available view on Windows and to null elsewhere.
 *
 * @api
 */
final class RemoteHeader
{
    public const STATES = ['ready', 'unmapped_agent_installed', 'unmapped', 'not_configured', 'disabled', 'offline'];

    /**
     * @param array{family:string,has_desktop:?bool,server:bool,enabled:bool,configured:bool,node:?string,agent_installed:bool,offline:bool,can_launch:bool,can_manage:bool,tools_on:bool} $f
     * @return array{state:string,views:list<array<string,mixed>>,primary:?string,hint:string,can_map_manually:bool}
     */
    public static function decide(array $f): array
    {
        $state = match (true) {
            !$f['enabled'] => 'disabled',
            !$f['configured'] => 'not_configured',
            $f['node'] === null && $f['agent_installed'] => 'unmapped_agent_installed',
            $f['node'] === null => 'unmapped',
            $f['offline'] => 'offline',
            default => 'ready',
        };
        $stateReason = match ($state) {
            'disabled' => 'MeshCentral is switched off.',
            'not_configured' => 'MeshCentral is not fully set up.',
            'unmapped_agent_installed', 'unmapped' => 'This device is not mapped to a MeshCentral node yet.',
            default => null,
        };
        $reasons = [
            'desktop' => !$f['can_launch'] ? 'You are not allowed to open a remote session.' : $stateReason,
            'terminal' => !$f['can_manage'] ? 'You are not allowed to use the terminal.' : (!$f['tools_on'] ? 'The remote tools are switched off.' : $stateReason),
            'files' => !$f['can_manage'] ? 'You are not allowed to use the file view.' : (!$f['tools_on'] ? 'The remote tools are switched off.' : $stateReason),
        ];
        $labels = ['desktop' => 'Remote desktop', 'terminal' => 'Terminal', 'files' => 'Files'];
        $views = [];
        foreach ($labels as $v => $label) {
            $row = ['view' => $v, 'label' => $label, 'available' => $reasons[$v] === null];
            if ($reasons[$v] !== null) {
                $row['reason'] = $reasons[$v];
            }
            $views[] = $row;
        }
        $avail = array_column(array_filter($views, static fn (array $r): bool => $r['available']), 'view');

        $windows = $f['family'] === 'windows';
        $wantDesktop = $windows ? $f['has_desktop'] !== false : ($f['has_desktop'] === true && !$f['server']);
        $want = $wantDesktop ? 'desktop' : 'terminal';
        $primary = in_array($want, $avail, true) ? $want : ($windows && $avail !== [] ? $avail[0] : null);

        return ['state' => $state, 'views' => $views, 'primary' => $primary, 'hint' => self::hint($state, $f), 'can_map_manually' => $f['configured'] && $f['node'] === null && $f['can_manage']];
    }

    /** @param array<string,mixed> $f */
    private static function hint(string $state, array $f): string
    {
        return match ($state) {
            'disabled' => 'Remote access through MeshCentral is switched off. An administrator can turn it on in the RMM settings.',
            'not_configured' => 'MeshCentral is switched on but not set up yet. An administrator needs to add its address and login key in the RMM settings.',
            'unmapped_agent_installed' => 'The Mesh agent is installed but this device is not mapped yet: restart the RivetIT agent on it, or map the node by hand.',
            'unmapped' => $f['can_manage'] ? 'The Mesh agent is not installed on this device yet. Install it from the device page, then it maps itself.' : 'The Mesh agent is not installed on this device yet. A technician with remote tools access can install it.',
            'offline' => 'The device is offline. Remote sessions open once it checks in again.',
            default => $f['tools_on'] ? 'Ready.' : 'Ready. The terminal and file views are switched off in the remote-tools settings.',
        };
    }
}
