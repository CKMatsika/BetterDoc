<?php

declare(strict_types=1);

namespace App\Services\Auth;

use LDAP\Result;
use RuntimeException;

/**
 * Offline LAN LDAP / Active Directory / FreeIPA directory connector.
 * Requires LDAPS or StartTLS when enabled in production environments.
 */
class LdapDirectoryService
{
    public function isEnabled(): bool
    {
        return config()->boolean('sentinel.ldap.enabled', false);
    }

    /**
     * @return array{dn: string, email: string, name: string, service_number: ?string}|null
     */
    public function authenticate(string $username, string $password): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        if (! function_exists('ldap_connect')) {
            throw new RuntimeException('PHP LDAP extension is not installed on this runtime.');
        }

        $host = config()->string('sentinel.ldap.host', '');
        $port = config()->integer('sentinel.ldap.port', 636);
        $useLdaps = config()->boolean('sentinel.ldap.use_ldaps', true);

        if ($host === '' || $password === '') {
            return null;
        }

        $scheme = $useLdaps ? 'ldaps' : 'ldap';
        $conn = @ldap_connect("{$scheme}://{$host}:{$port}");
        if ($conn === false) {
            return null;
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);

        if (! $useLdaps) {
            @ldap_start_tls($conn);
        }

        $bindDn = config()->string('sentinel.ldap.bind_dn', '');
        $bindPass = config()->string('sentinel.ldap.bind_password', '');

        if ($bindDn !== '' && ! @ldap_bind($conn, $bindDn, $bindPass)) {
            @ldap_unbind($conn);

            return null;
        }

        $baseDn = config()->string('sentinel.ldap.base_dn', '');
        $escapedUser = ldap_escape($username, '', LDAP_ESCAPE_FILTER);
        $search = @ldap_search($conn, $baseDn, "(|(mail={$escapedUser})(uid={$escapedUser})(sAMAccountName={$escapedUser}))");

        if (! $search instanceof Result) {
            @ldap_unbind($conn);

            return null;
        }

        $entries = ldap_get_entries($conn, $search);
        if (! is_array($entries) || ! isset($entries[0]) || ! is_array($entries[0])) {
            @ldap_unbind($conn);

            return null;
        }

        $entry = $entries[0];
        $userDn = is_string($entry['dn'] ?? null) ? $entry['dn'] : '';

        if ($userDn === '' || ! @ldap_bind($conn, $userDn, $password)) {
            @ldap_unbind($conn);

            return null;
        }

        @ldap_unbind($conn);

        $mailAttr = is_array($entry['mail'] ?? null) ? ($entry['mail'][0] ?? null) : null;
        $cnAttr = is_array($entry['cn'] ?? null) ? ($entry['cn'][0] ?? null) : null;
        $empAttr = is_array($entry['employeenumber'] ?? null) ? ($entry['employeenumber'][0] ?? null) : null;

        return [
            'dn' => $userDn,
            'email' => is_string($mailAttr) ? $mailAttr : $username,
            'name' => is_string($cnAttr) ? $cnAttr : $username,
            'service_number' => is_string($empAttr) ? $empAttr : null,
        ];
    }
}
