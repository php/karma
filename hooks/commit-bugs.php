<?php

// This script is intended to be called from post-commit. It assumes that all
//  the appropriate variables and functions are defined.

// -----------------------------------------------------------------------------------------------------------------------------
// Constants
$bug_pattern = '/(?:(pecl|pear|php)\s*)?(?:bug|#)[\s#:]*([0-9]+)/iuX';
$bug_url_prefixes = array(
    'pear' => 'http://pear.php.net/bugs',
    'pecl' => 'https://bugs.php.net',
    'php' => 'https://bugs.php.net',
    '' => 'https://bugs.php.net',
);
$bug_rpc_url = 'https://bugs.php.net/rpc.php';
//$viewvc_url_prefix = 'http://svn.php.net/viewvc/?view=revision&revision=';

// -----------------------------------------------------------------------------------------------------------------------------
// Get the list of mentioned bugs from the commit log
if (preg_match_all($bug_pattern, $commit_info['log_message'], $matched_bugs, PREG_SET_ORDER) < 1) {
    // If no bugs matched, we don't have to do anything.
    return;
}

// -----------------------------------------------------------------------------------------------------------------------------
// Pick the default bug project out the of the path in the first changed dir
switch (strtolower(substr($commit_info['dirs_changed'][0], 0, 4))) {
    case 'pear':
        $bug_project_default = 'pear';
        break;
    case 'pecl':
        $bug_project_default = 'pecl';
        break;
    default:
        $bug_project_default = '';
        break;
}

// -----------------------------------------------------------------------------------------------------------------------------
// Process the matches
$bug_list = array();
foreach ($matched_bugs as $matched_bug) {
    $bug = array();
    $bug['project'] = $matched_bug[1] === "" ? $bug_project_default : strtolower($matched_bug[1]);
    $bug['number'] = intval($matched_bug[2]);
    $bugid = $bug['project'] . $bug['number'];
    $url_prefix = isset($bug_url_prefixes[$bug['project']]) ? $bug_url_prefixes[$bug['project']] : $bug_url_prefixes[''];
    $bug['url'] = $url_prefix . '/' . $bug['number'];
    $bug['status'] = 'unknown';
    $bug['short_desc'] = '';
    $bug_list[$bugid] = $bug;
}

// -----------------------------------------------------------------------------------------------------------------------------
// Make an RPC call for each bug
include __DIR__ . '/secret.inc';
foreach ($bug_list as $k => $bug) {
    if (!in_array($bug["project"], array("php", "pecl", ""))) {
        continue;
    }

    $comment = "Automatic comment on behalf of {$commit_info['author']}\n" .
               "Revision: {$viewvc_url_prefix}{$REV}\n" .
               "Log: {$commit_info['log_message']}\n";

    $postdata = array(
                    'user' => $commit_info['author'],
                    'id' => $bug['number'],
                    'ncomment' => $comment,
                    'MAGIC_COOKIE' => $SVN_MAGIC_COOKIE,
                );
    if ($is_DEBUG) {
        unset($postdata['ncomment']);
        $postdata['getbug'] = 1;
    }
    array_walk($postdata, create_function('&$v, $k', '$v = rawurlencode($k) . "=" . rawurlencode($v);'));
    $postdata = implode('&', $postdata);

    // Hook an env var so emails can be resent without messing with bugs
    if (getenv('NOBUG')) {
        continue;
    }

    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $bug_rpc_url,
        CURLOPT_RETURNTRANSFER => TRUE,
        CURLOPT_POST => TRUE,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_POSTFIELDS => $postdata,
    ));

    $result = curl_exec($ch);

    if ($result === FALSE) {
        $bug_list[$k]['error'] = curl_error($ch);
    } else {
        $bug_server_data = json_decode($result, TRUE);
        if (isset($bug_server_data['result']['status'])) {
            $bug_list[$k]['status'] = $bug_server_data['result']['status']['status'];
            $bug_list[$k]['short_desc'] = $bug_server_data['result']['status']['sdesc'];
        } else {
            $bug_list[$k]['error'] = $bug_server_data['result']['error'];
        }
    }
    curl_close($ch);
}
unset($SVN_MAGIC_COOKIE);

// $bug_list is now available to later-running hooks
?>
