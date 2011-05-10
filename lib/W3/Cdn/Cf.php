<?php

if (!defined('W3TC_CDN_CF_TYPE_S3')) {
    define('W3TC_CDN_CF_TYPE_S3', 's3');
}

if (!defined('W3TC_CDN_CF_TYPE_CUSTOM')) {
    define('W3TC_CDN_CF_TYPE_CUSTOM', 'custom');
}

require_once W3TC_LIB_W3_DIR . '/Cdn/S3.php';

class W3_Cdn_Cf extends W3_Cdn_S3 {
    /**
     * Type
     *
     * @var string
     */
    var $type = '';

    /**
     * Initializes S3 object
     *
     * @param string $error
     * @return bool
     */
    function _init(&$error) {
        if (empty($this->type)) {
            $error = 'Empty type';

            return false;
        } elseif (!in_array($this->type, array(W3TC_CDN_CF_TYPE_S3, W3TC_CDN_CF_TYPE_CUSTOM))) {
            $error = 'Wrong type';

            return false;
        }

        if (empty($this->_config['key'])) {
            $error = 'Empty access key';

            return false;
        }

        if (empty($this->_config['secret'])) {
            $error = 'Empty secret key';

            return false;
        }

        if ($this->type == W3TC_CDN_CF_TYPE_S3 && empty($this->_config['bucket'])) {
            $error = 'Empty bucket';

            return false;
        }

        $this->_s3 = & new S3($this->_config['key'], $this->_config['secret'], false);

        return true;
    }

    /**
     * Returns origin
     *
     * @return string
     */
    function _get_origin() {
        if ($this->type == W3TC_CDN_CF_TYPE_S3) {
            $origin = sprintf('%s.s3.amazonaws.com', $this->_config['bucket']);
        } else {
            $origin = w3_get_host();
        }

        return $origin;
    }

    /**
     * Uploads files
     *
     * @param array $files
     * @param array $results
     * @param boolean $force_rewrite
     * @return void
     */
    function upload($files, &$results, $force_rewrite = false) {
        if ($this->type == W3TC_CDN_CF_TYPE_S3) {
            parent::upload($files, $results, $force_rewrite);
        } else {
            $results = $this->get_results($files, W3TC_CDN_RESULT_OK, 'OK');
        }
    }

    /**
     * Deletes files
     *
     * @param array $files
     * @param array $results
     * @return void
     */
    function delete($files, &$results) {
        if ($this->type == W3TC_CDN_CF_TYPE_S3) {
            parent::delete($files, $results);
        } else {
            $results = $this->get_results($files, W3TC_CDN_RESULT_OK, 'OK');
        }
    }

    /**
     * Returns array of CDN domains
     *
     * @return string
     */
    function get_domains() {
        if (!empty($this->_config['cname'])) {
            return (array) $this->_config['cname'];
        } elseif (!empty($this->_config['id'])) {
            $domain = sprintf('%s.cloudfront.net', $this->_config['id']);

            return array(
                $domain
            );
        }

        return array();
    }

    /**
     * Tests CF
     *
     * @param string $error
     * @return boolean
     */
    function test(&$error) {
        if ($this->type == W3TC_CDN_CF_TYPE_S3) {
            if (!parent::test($error)) {
                return false;
            }
        } elseif ($this->type == W3TC_CDN_CF_TYPE_CUSTOM) {
            if (!$this->_init($error)) {
                return false;
            }
        }

        /**
         * Search active CF distribution
         */
        $this->set_error_handler();

        $dists = @$this->_s3->listDistributions();

        $this->restore_error_handler();

        if ($dists === false) {
            $error = sprintf('Unable to list distributions (%s).', $this->get_last_error());

            return false;
        }

        if (!count($dists)) {
            $error = 'No distributions found.';

            return false;
        }

        $dist = false;
        $origin = $this->_get_origin();

        foreach ((array) $dists as $_dist) {
            if (isset($_dist['origin']) && $_dist['origin'] == $origin) {
                $dist = $_dist;
                break;
            }
        }

        if (!$dist) {
            $error = sprintf('Distribution for origin "%s" not found.', $origin);

            return false;
        }

        if (!$dist['enabled']) {
            $error = sprintf('Distribution for origin "%s" is disabled.', $origin);

            return false;
        }

        if (!empty($this->_config['cname'])) {
            $domains = (array) $this->_config['cname'];
            $cnames = (isset($dist['cnames']) ? (array) $dist['cnames'] : array());

            foreach ($domains as $domain) {
                $_domains = array_map('trim', explode(',', $domain));

                foreach ($_domains as $_domain) {
                    if (!in_array($_domain, $cnames)) {
                        $error = sprintf('Domain name %s is not in distribution CNAME list.', $_domain);

                        return false;
                    }
                }
            }
        } elseif (!empty($this->_config['id'])) {
            $domain = $this->get_domain();

            if ($domain != $dist['domain']) {
                $error = sprintf('Distribution domain name mismatch (%s != %s).', $domain, $dist['domain']);

                return false;
            }
        }

        return true;
    }

    /**
     * Create bucket
     *
     * @param string $container_id
     * @param string $error
     * @return boolean
     */
    function create_container(&$container_id, &$error) {
        if ($this->type == W3TC_CDN_CF_TYPE_S3) {
            if (!parent::create_container($container_id, $error)) {
                return false;
            }
        } elseif ($this->type == W3TC_CDN_CF_TYPE_CUSTOM) {
            if (!$this->_init($error)) {
                return false;
            }
        }

        $cnames = array();

        if (!empty($this->_config['cname'])) {
            $domains = (array) $this->_config['cname'];

            foreach ($domains as $domain) {
                $_domains = array_map('trim', explode(',', $domain));

                foreach ($_domains as $_domain) {
                    $cnames[] = $_domain;
                }
            }
        }

        $origin = $this->_get_origin();

        $this->set_error_handler();

        $dist = @$this->_s3->createDistribution($origin, $this->type, true, $cnames);

        $this->restore_error_handler();

        if (!$dist) {
            $error = sprintf('Unable to create distribution for origin %s (%s).', $origin, $this->get_last_error());

            return false;
        }

        $matches = null;

        if (preg_match('~^(.+)\.cloudfront\.net$~', $dist['domain'], $matches)) {
            $container_id = $matches[1];
        }

        return true;
    }

    /**
     * Returns via string
     *
     * @return string
     */
    function get_via() {
        $domain = $this->get_domain();

        $via = ($domain ? $domain : 'N/A');

        return sprintf('Amazon Web Services: CloudFront: %s', $via);
    }

    /**
     * Update distribution CNAMEs
     *
     * @param string $error
     * @return bool
     */
    function update_cnames(&$error) {
        if (!$this->_init($error)) {
            return false;
        }

        $this->set_error_handler();

        $dists = @$this->_s3->listDistributions();

        $this->restore_error_handler();

        if ($dists === false) {
            $error = sprintf('Unable to list distributions (%s)', $this->get_last_error());

            return false;
        }

        $dist_id = false;
        $origin = $this->_get_origin();

        foreach ((array) $dists as $dist) {
            if (isset($dist['origin']) && $dist['origin'] == $origin) {
                $dist_id = $dist['id'];
                break;
            }
        }

        if (!$dist_id) {
            $error = sprintf('Distribution ID for origin "%s" not found.', $origin);

            return false;
        }

        $this->set_error_handler();

        $dist = @$this->_s3->getDistribution($dist_id);

        $this->restore_error_handler();

        if (!$dist) {
            $error = sprintf('Unable to get distribution by ID %s (%s)', $dist_id, $this->get_last_error());
        }

        $dist['cnames'] = (isset($this->_config['cname']) ? (array) $this->_config['cname'] : array());

        $this->set_error_handler();

        $dist = @$this->_s3->updateDistribution($dist);

        $this->restore_error_handler();

        if (!$dist) {
            $error = sprintf('Unable to update distribution %s (%s)', json_encode($dist), $this->get_last_error());

            return false;
        }

        return true;
    }
}