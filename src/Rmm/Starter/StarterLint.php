<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Starter;

/**
 * The guard rail every starter script passes before it is accepted into a pack: a denylist of things a built-in script must never do (create users,
 * change passwords, weaken security tooling, download or execute from the internet, send data to another host, embed secrets or addresses) and, for a
 * `read_only` script, the commands that change the machine. It is a safety net for the pack author and the reviewer, not a sandbox: scripts still run as
 * the agent's service account, and the signature plus the approval flags are what protect a device.
 *
 * `$language` is `powershell` or `bash`; the PowerShell 5.1 syntax rule only applies to the first.
 *
 * Every finding is `[label, matched text]`. An empty list means clean.
 *
 * @api
 */
final class StarterLint
{
    /** Always forbidden: label => PCRE. */
    private const FORBIDDEN = [
        'download from the internet' => '/\b(Invoke-WebRequest|Invoke-RestMethod|Start-BitsTransfer|Net\.WebClient|System\.Net\.WebClient|DownloadString|DownloadFile|DownloadData|UploadString|UploadFile|UploadData|Send-MailMessage|System\.Net\.Http|HttpClient|BitsTransfer)\b/i',
        'download alias or tool' => '/(?<![\w.\/$-])(iwr|irm|curl|wget)(?![\w-])/i',
        'dynamic code execution' => '/(?<![\w-])(Invoke-Expression|iex)(?![\w-])|(?<![\w.\/-])eval\s|\|\s*(sudo\s+)?(ba|z|da|k)?sh\b|\bsource\s+<\(|\bbash\s+<\(/i',
        'encoded or base64 execution' => '/base64\s+(-d|--decode|-D)\b|FromBase64String|-EncodedCommand|\s-enc\s/i',
        'user or group management' => '/\b(Add-LocalGroupMember|Remove-LocalGroupMember|New-LocalUser|Set-LocalUser|Remove-LocalUser|Enable-LocalUser|Disable-LocalUser|New-LocalGroup|New-ADUser|Set-ADAccountPassword)\b|\bnet1?\s+(user|localgroup|accounts)\b|(?<![\w.\/-])(useradd|usermod|userdel|adduser|deluser|chpasswd|gpasswd|visudo|groupadd|groupmod|vipw)(?![\w-])/i',
        'password change' => '/(?<![\w\/.-])(?<!getent )passwd\b(?!\s*[:=)\]"\'])|\bchage\s+-|\bSet-ADAccountPassword\b|\bConvertTo-SecureString\b|\bnet\s+user\b/i',
        'security tooling disabled' => '/Set-MpPreference|Add-MpPreference|DisableRealtimeMonitoring|Set-NetFirewallProfile\b[^\n]*-Enabled\s+(False|0)|netsh\s+advfirewall\s+set\s+\w+\s+state\s+off|Disable-NetFirewallRule|Stop-Service\b[^\n]*(WinDefend|mpssvc|Sense|wscsvc|MsMpSvc)|\bsc(\.exe)?\s+(stop|config|delete)\s+(WinDefend|mpssvc|Sense|MsMpSvc)|systemctl\s+(stop|disable|mask)\s+[^\n]*(ufw|firewalld|fail2ban|auditd|apparmor|selinux|nftables|iptables|rivet)|setenforce\s+0|\bufw\s+(--force\s+)?disable|\biptables\s+-F|\bnft\s+flush|auditctl\s+-e\s*0|Uninstall-WindowsFeature/i',
        'sends data to another host' => '/(?<![\w.\/-])(scp|sftp|rsync|ncat|netcat|nc|socat|telnet|ftp|tftp)\s|\/dev\/(tcp|udp)\/|(?m)(^|[;&|`(]\s*)(sudo\s+)?ssh\s|Invoke-Command\b[^\n]*-ComputerName|New-PSSession|Enter-PSSession/i',
        'embedded secret' => '/BEGIN [A-Z ]*PRIVATE KEY|AKIA[0-9A-Z]{16}|\bgh[pousr]_[A-Za-z0-9]{20,}|\bxox[abpr]-[A-Za-z0-9-]{10,}|(?i:password|passwd|secret|token|apikey|api_key)\s*[:=]\s*[\'"][^\'"\s]{4,}[\'"]/',
        'web address' => '#(?i)\b(https?|ftp)://#',
        'disk or boot destruction' => '/\b(Format-Volume|Clear-Disk|Initialize-Disk|Remove-Partition|diskpart|mkfs(\.\w+)?|fdisk|parted|wipefs|shred|bcdedit)\b|\bdd\s+if=|\bcipher\s+\/w/i',
        'unguarded root delete' => '#\brm\s+-[a-zA-Z]+\s+(--\s+)?(/|/\*|~|~/\*|\$HOME|"\$HOME")(\s|$)|Remove-Item\b[^\n]*\s[\'"]?[A-Za-z]:\\\\?[\'"]?\s*(-|$)#i',
    ];

    /** Forbidden unless the script is `disruptive`. */
    private const REBOOT = '/\b(Restart-Computer|Stop-Computer)\b|(?<![\w\/.-])(shutdown(\.exe)?|reboot|poweroff|halt)\s+[-\/+]|\bsystemctl\s+(reboot|poweroff|halt|kexec)\b|\binit\s+[06]\b/i';

    /** Forbidden in a `read_only` script (it only reads and reports). */
    private const MUTATING = [
        'changes a service' => '/\b(Set-Service|Stop-Service|Start-Service|Restart-Service|Resume-Service|Suspend-Service)\b|\bsystemctl\s+(restart|stop|start|reload|enable|disable|mask|unmask|daemon-reload|kill|reset-failed)\b|\bservice\s+\S+\s+(restart|stop|start)\b|\bsc(\.exe)?\s+(start|stop|config)\b|\bnet\s+(start|stop)\b/i',
        'changes the registry' => '/\b(Set-ItemProperty|New-ItemProperty|Remove-ItemProperty|New-Item\s+-Path\s+[\'"]?HK|reg(\.exe)?\s+(add|delete|import))\b/i',
        'changes packages' => '/\b(apt|apt-get)\s+(-\S+\s+)*(install|remove|purge|upgrade|dist-upgrade|full-upgrade|clean|autoclean|autoremove|update)\b|\b(dnf|yum)\s+(-\S+\s+)*(install|remove|erase|update|upgrade|clean|makecache)\b|\bzypper\s+(-\S+\s+)*(install|remove|update|up|dup|clean|refresh|ref)\b|\bwinget\s+(install|uninstall|upgrade\s+--all|upgrade\s+--id)\b|\bInstall-(Package|Module|Update)\b|\bUninstall-Package\b/i',
        'changes files' => '/\bsed\s+-[a-z]*i|\bchmod\b|\bchown\b|(?<![\w.\/-])mv\s|\bjournalctl\b[^\n]*--vacuum|\bMove-Item\b|\bRename-Item\b|\bSet-Content\b|\bAdd-Content\b/i',
        'changes settings' => '/\b(Start-MpScan|Update-MpSignature|Clear-DnsClientCache|Set-Date|Set-TimeZone|Enable-BitLocker|Disable-BitLocker|Set-NetIPInterface)\b|ipconfig\s+\/flushdns|w32tm\s+\/(resync|config)|\bhwclock\b[^\n]*--(set|systohc)|\btimedatectl\s+set|\bsysctl\s+-w/i',
    ];

    /**
     * @return list<array{0:string,1:string}> findings
     */
    public static function check(string $body, string $risk, string $language = 'powershell'): array
    {
        // Whole-line comments are documentation, not behaviour.
        $body = (string) preg_replace('/^[ \t]*#.*$/m', '', $body);
        $out = [];
        foreach (self::FORBIDDEN as $label => $re) {
            if (preg_match($re, $body, $m) === 1) {
                $out[] = [$label, trim($m[0])];
            }
        }
        if ($risk !== 'disruptive' && preg_match(self::REBOOT, $body, $m) === 1) {
            $out[] = ['reboot or shutdown outside a disruptive script', trim($m[0])];
        }
        if ($risk === 'read_only') {
            // A package manager run as a simulation (-s, --simulate, --dry-run) changes nothing.
            $text = (string) preg_replace('/^.*\b(apt|apt-get)\s(.*\s)?(-\w*s\w*|--simulate|--dry-run|--just-print|--no-act)(\s.*)?$/m', '', $body);
            foreach (self::MUTATING as $label => $re) {
                if (preg_match($re, $text, $m) === 1) {
                    $out[] = [$label . ' in a read-only script', trim($m[0])];
                }
            }
        }
        // Windows PowerShell 5.1 is the baseline: refuse syntax it cannot parse.
        if ($language === 'powershell' && preg_match('/\?\?|\?\.\w|\s&&\s|\s\|\|\s(?![\'"$(])|ForEach-Object\s+-Parallel|\bclean\s*\{/', $body, $m) === 1) {
            $out[] = ['syntax newer than Windows PowerShell 5.1', trim($m[0])];
        }

        return $out;
    }
}
