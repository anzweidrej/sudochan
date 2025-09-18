<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Resolver;

use Sudochan\Cache;
use Sudochan\Utils\Shell;

class DNSResolver
{
    /**
     * Check the client's IP against configured DNSBLs and abort on match.
     *
     * @global array $config
     * @return void
     */
    public static function checkDNSBL(): void
    {
        global $config;

        if (self::isIPv6()) {
            return;
        } // No IPv6 support yet.

        if (!isset($_SERVER['REMOTE_ADDR'])) {
            return;
        } // Fix your web server configuration

        if (in_array($_SERVER['REMOTE_ADDR'], $config['dnsbl_exceptions'])) {
            return;
        }

        $ipaddr = self::ReverseIPOctets($_SERVER['REMOTE_ADDR']);

        foreach ($config['dnsbl'] as $blacklist) {
            if (!is_array($blacklist)) {
                $blacklist = [$blacklist];
            }

            if (($lookup = str_replace('%', $ipaddr, $blacklist[0])) == $blacklist[0]) {
                $lookup = $ipaddr . '.' . $blacklist[0];
            }

            if (!($ip = self::DNS($lookup))) {
                continue;
            } // not in list

            $blacklist_name = isset($blacklist[2]) ? $blacklist[2] : $blacklist[0];

            if (!isset($blacklist[1])) {
                // If you're listed at all, you're blocked.
                error(sprintf($config['error']['dnsbl'], $blacklist_name));
            } elseif (is_array($blacklist[1])) {
                foreach ($blacklist[1] as $octet) {
                    if ($ip == $octet || $ip == '127.0.0.' . $octet) {
                        error(sprintf($config['error']['dnsbl'], $blacklist_name));
                    }
                }
            } elseif (is_callable($blacklist[1])) {
                if ($blacklist[1]($ip)) {
                    error(sprintf($config['error']['dnsbl'], $blacklist_name));
                }
            } else {
                if ($ip == $blacklist[1] || $ip == '127.0.0.' . $blacklist[1]) {
                    error(sprintf($config['error']['dnsbl'], $blacklist_name));
                }
            }
        }
    }

    /**
     * Determine whether the current remote address is IPv6.
     *
     * @return bool True if IPv6, false otherwise.
     */
    public static function isIPv6(): bool
    {
        return strstr($_SERVER['REMOTE_ADDR'], ':') !== false;
    }

    /**
     * Reverse the octets of an IPv4 address.
     *
     * @param string $ip IPv4 address.
     * @return string Reversed octet string.
     */
    public static function ReverseIPOctets(string $ip): string
    {
        return implode('.', array_reverse(explode('.', $ip)));
    }

    /**
     * Perform a reverse DNS (PTR) lookup for an IP address.
     *
     * @param string $ip_addr IP address to lookup.
     * @return string Hostname or the original IP on failure.
     */
    public static function rDNS(string $ip_addr): string
    {
        global $config;

        if ($config['cache']['enabled'] && ($host = Cache::get('rdns_' . $ip_addr))) {
            return $host;
        }

        if (!$config['dns_system']) {
            $host = gethostbyaddr($ip_addr);
        } else {
            $resp = Shell::shell_exec_error('host -W 1 ' . $ip_addr);
            if (preg_match('/domain name pointer ([^\s]+)$/', $resp, $m)) {
                $host = $m[1];
            } else {
                $host = $ip_addr;
            }
        }

        if ($config['cache']['enabled']) {
            Cache::set('rdns_' . $ip_addr, $host);
        }

        return $host;
    }

    /**
     * Resolve a hostname to an IPv4 address.
     *
     * @param string $host Hostname to resolve.
     * @return string|false IPv4 address string or false if resolution failed.
     */
    public static function DNS(string $host): string|false
    {
        global $config;

        if ($config['cache']['enabled'] && ($ip_addr = Cache::get('dns_' . $host))) {
            return $ip_addr != '?' ? $ip_addr : false;
        }

        if (!$config['dns_system']) {
            $ip_addr = gethostbyname($host);
            if ($ip_addr == $host) {
                $ip_addr = false;
            }
        } else {
            $resp = Shell::shell_exec_error('host -W 1 ' . $host);
            if (preg_match('/has address ([^\s]+)$/', $resp, $m)) {
                $ip_addr = $m[1];
            } else {
                $ip_addr = false;
            }
        }

        if ($config['cache']['enabled']) {
            Cache::set('dns_' . $host, $ip_addr !== false ? $ip_addr : '?');
        }

        return $ip_addr;
    }
}
