<?php
if (!defined('ABSPATH')) exit;

/**
 * Which Webruno server should this site talk to: panel.webruno.ir (Iranian sites) or panel.webruno.com (international)?
 *
 * An Iranian site is a site whose language is Persian OR whose WordPress server is in Iran.
 * The domain, the TLD and the currency are NOT criteria.
 *
 * Decision (Webruno_Region::detect):
 *   1. get_user_locale() / get_locale() starts with "fa"  ->  ir. (Nothing is sent anywhere.)
 *   2. Otherwise ask panel.webruno.com first (4 s timeout), and only if it did not answer, panel.webruno.ir.
 *      The first one that answers is final. It sees our server's IP, as any request does, and nothing else.
 *   3. If neither answered, only the locale counts (-> intl) and the UI says so openly.
 *
 * There is never a silent fallback to the other server when connecting: if the chosen server is unreachable the
 * admin is told so and can change the server manually.
 *
 * Options: webruno_crm_region ('ir'|'intl'), webruno_crm_region_reasons (list of reason codes, 'manual' = chosen by hand).
 */
class Webruno_Region {

    const OPTION_REGION  = 'webruno_crm_region';
    const OPTION_REASONS = 'webruno_crm_region_reasons';
    const TIMEOUT        = 4;
    const PATH           = '/customer/customer/webruno-plugin-region';

    const BASE_COM = 'https://panel.webruno.com';
    const BASE_IR  = 'https://panel.webruno.ir';

    /** Panel base URL for a region. */
    public static function base_for($region) {
        return $region === 'ir' ? self::BASE_IR : self::BASE_COM;
    }

    /** Value of the "webruno_crm_domain" option for a region. */
    public static function domain_for($region) {
        return $region === 'ir' ? 'webruno.ir' : 'webruno.com';
    }

    /** True when the admin's or the site's language is Persian. */
    public static function locale_is_persian() {
        $user_locale = function_exists('get_user_locale') ? (string) get_user_locale() : '';
        // The site language counts too, not only the language of the admin who happens to be logged in.
        $site_locale = function_exists('get_locale') ? (string) get_locale() : '';
        return self::is_fa($user_locale) || self::is_fa($site_locale);
    }

    private static function is_fa($locale) {
        $locale = strtolower(str_replace('-', '_', trim((string) $locale)));
        return $locale === 'fa' || strpos($locale, 'fa_') === 0;
    }

    /**
     * Ask one Webruno panel which region our server's IP is in.
     * @return array|null ['region' => 'ir'|'intl', 'country' => 'IR'|...|null] or null (no usable answer)
     */
    public static function ask_server($base) {
        if (strpos($base, 'https://') !== 0) {
            return null;
        }
        $resp = wp_remote_get($base . self::PATH, [
            'timeout'             => self::TIMEOUT,
            'redirection'         => 0,
            'limit_response_size' => 2048,
            'headers'             => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
            return null;
        }
        $body = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($body) || empty($body['success']) || !isset($body['region'])) {
            return null;
        }
        if ($body['region'] !== 'ir' && $body['region'] !== 'intl') {
            return null; // "unknown" is not an answer
        }
        $country = (isset($body['country']) && is_string($body['country']) && preg_match('/^[A-Za-z]{2}$/', $body['country']))
            ? strtoupper($body['country']) : null;
        return ['region' => $body['region'], 'country' => $country];
    }

    /**
     * Work out the region. The network is used only when $use_network is true and the locale is not Persian.
     * @return array {region, base, domain, reasons[], manual(bool), server_answered(bool), message(string)}
     */
    public static function detect($use_network = true) {
        $reasons = [];

        if (self::locale_is_persian()) {
            $reasons[] = 'locale_fa';
            return self::result('ir', $reasons, false, true);
        }
        $reasons[] = 'locale_not_fa';

        if (!$use_network) {
            $reasons[] = 'geo_not_checked';
            return self::result('intl', $reasons, false, false);
        }

        foreach ([['com', self::BASE_COM], ['ir', self::BASE_IR]] as $server) {
            $answer = self::ask_server($server[1]);
            if ($answer === null) {
                $reasons[] = 'geo_unreachable_' . $server[0];
                continue;
            }
            // the first server that answers is final
            $reasons[] = 'geo_answered_' . $server[0];
            $reasons[] = $answer['region'] === 'ir' ? 'geo_ir' : 'geo_intl';
            if ($answer['country']) {
                $reasons[] = 'country_' . strtolower($answer['country']);
            }
            return self::result($answer['region'], $reasons, false, true);
        }

        // neither panel answered: locale only (-> intl), and the UI says so
        $reasons[] = 'geo_unavailable';
        return self::result('intl', $reasons, false, false);
    }

    private static function result($region, $reasons, $manual, $decided_by_answer) {
        return [
            'region'          => $region,
            'base'            => self::base_for($region),
            'domain'          => self::domain_for($region),
            'reasons'         => array_values($reasons),
            'manual'          => (bool) $manual,
            'server_answered' => (bool) $decided_by_answer,
            'message'         => in_array('geo_unavailable', $reasons, true) ? self::unavailable_message() : '',
        ];
    }

    public static function unavailable_message() {
        return __('Webruno could not be reached to check where this server is located, so only the site language was used (not Persian, so the international server was chosen). If this site is hosted in Iran or serves Iranian customers, use "Change manually" to pick webruno.ir.', 'webruno-crm');
    }

    /** Saved state (no network). Falls back to a locale-only guess when nothing has been saved yet. */
    public static function get_state() {
        $region  = get_option(self::OPTION_REGION, '');
        $reasons = get_option(self::OPTION_REASONS, []);
        $reasons = is_array($reasons) ? array_values(array_filter($reasons, 'is_string')) : [];

        if ($region === 'ir' || $region === 'intl') {
            return self::result($region, $reasons, in_array('manual', $reasons, true), true);
        }
        return self::detect(false);
    }

    /** Detect with network and save, unless the admin chose manually (then the manual choice stays). */
    public static function refresh() {
        $saved = self::get_state();
        if ($saved['manual']) {
            return $saved;
        }
        $state = self::detect(true);
        self::save($state['region'], $state['reasons']);
        return $state;
    }

    /** Manual choice: 'ir', 'intl', or 'auto' (forget the choice and detect again). */
    public static function set_manual($choice) {
        if ($choice === 'auto') {
            delete_option(self::OPTION_REGION);
            delete_option(self::OPTION_REASONS);
            return self::refresh();
        }
        if ($choice !== 'ir' && $choice !== 'intl') {
            return self::get_state();
        }
        self::save($choice, ['manual']);
        return self::get_state();
    }

    private static function save($region, $reasons) {
        update_option(self::OPTION_REGION, $region, false);
        update_option(self::OPTION_REASONS, array_values($reasons), false);
    }

    /** Human-readable explanations (translatable) for the reason codes. */
    public static function describe_reasons($reasons) {
        $out = [];
        foreach ((array) $reasons as $code) {
            switch ($code) {
                case 'manual':
                    $out[] = __('You chose this server manually.', 'webruno-crm');
                    break;
                case 'locale_fa':
                    $out[] = __('The site language is Persian.', 'webruno-crm');
                    break;
                case 'geo_ir':
                    $out[] = __('This server is located in Iran.', 'webruno-crm');
                    break;
                case 'geo_intl':
                    $out[] = __('This server is located outside Iran.', 'webruno-crm');
                    break;
                case 'locale_not_fa':
                    $out[] = __('The site language is not Persian.', 'webruno-crm');
                    break;
                case 'geo_unavailable':
                    $out[] = __('The server location could not be checked.', 'webruno-crm');
                    break;
                default:
                    break; // technical codes (unreachable_*, answered_*, country_*) are not shown
            }
        }
        return $out;
    }
}
