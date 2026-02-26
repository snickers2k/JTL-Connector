<?php
defined('BOOTSTRAP') or die('Access denied');

use Tygh\Registry;

fn_register_hooks(
    'get_companies',
    'delete_company',
    'change_order_status_post',
    'dispatch_before_display'
);

