<?php
if (!defined('ABSPATH')) { exit; }

require_once dirname(__FILE__) . '/jwt-auth-middleware.php';
require_once dirname(__FILE__) . '/rest-api-access.php';
require_once dirname(__FILE__) . '/rest-endpoint-restrictions.php';
require_once dirname(__FILE__) . '/security-headers.php';
require_once dirname(__FILE__) . '/xmlrpc-hardening.php';
require_once dirname(__FILE__) . '/csrf-protection.php';
require_once dirname(__FILE__) . '/unblock-ip.php';
