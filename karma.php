#!/usr/bin/env php
<?php
/*
 * (c) 2011 David Soria Parra <dsp at php dot net>
 *
 * Licensed under the terms of the MIT license.
 */

namespace Karma;

const KARMA_URL = '/repository/karma.git';
const KARMA_FILE = 'global_avail';

const REPO_URL = '/repository/php-src.git';
const PREFIX = 'php-src/';

class GitReceiveHook
{
    const GIT_EXECUTABLE = 'git';
    const INPUT_PATTERN = '@^([0-9a-f]{40}) SP ([0-9a-f]{40}) SP (\w+)$@i';

    public function hookInput()
    {
        $parsed_input = [];
        while (!feof(STDIN)) {
            $line = fgets(STDIN);
            if (preg_match(self::INPUT_PATTERN, $line, $matches)) {
                $parsed_input[] = [
                    'old'     => $matches[1],
                    'new'     => $matches[2],
                    'refname' => $matches[3]];
            }
        }
        return $parsed_input;
    }

    public function getKarmaFile()
    {
        exec(
            sprintf('%s --git-dir=%s show master:%s',
                self::GIT_EXECUTABLE, KARMA_URL, KARMA_FILE), $output);
        return $output;
    }

    private function getReceivedPathsForRange($old, $new)
    {
        $output = [];
        exec(
            sprintf('%s --git-dir=%s log --name-only --pretty=format:"" %s..%s',
                self::GIT_EXECUTABLE, REPO_URL, $old, $new), $output);
        return $output;
    }

    public function getReceivedPaths()
    {
        $parsed_input = $this->hookInput();

        $paths = array_map(
            function ($input) {
                return $this->getReceivedPathsForRange($input['old'], $input['new']);
            },
            $parsed_input);

        /* remove empty lines, and flattern the array */
        $flattend = array_reduce($paths, 'array_merge', []);
        $paths    = array_filter($flattend);

        return array_unique($paths);
    }
}


function deny($reason)
{
    fwrite(STDERR, $reason . "\n");
    exit(1);
}

function accept()
{
    exit(0);
}

function get_karma_for_paths($username, array $paths, array $avail_lines)
{
    $access = array_fill_keys($paths, 'unavail');
    foreach ($avail_lines as $acl_line) {
        $acl_line = trim($acl_line);
        if ('' === $acl_line || '#' === $acl_line{0}) {
            continue;
        }

        @list($avail, $user_str, $path_str) = explode('|', $acl_line);

        $allowed_paths = explode(',', $path_str);
        $allowed_users = explode(',', $user_str);

        /* ignore lines which don't contain our users or apply to all users */
        if (!in_array($username, $allowed_users) && !empty($user_str)) {
            continue;
        }

        if (!in_array($avail, ['avail', 'unavail'])) {
            continue;
        }

        if (empty($path_str)) {
            $access = array_fill_keys($paths, $avail);
        } else {
            foreach ($access as $requested_path => $is_avail) {
                foreach ($allowed_paths as $path) {
                    if (fnmatch($path . '*', $requested_path)) {
                        $access[$requested_path] = $avail;
                    }
                }
            }
        }
    }

    return $access;
}

function get_unavail_paths($username, array $paths, \Iterator $avail_lines)
{
    return
        array_keys(
            array_filter(
                get_karma_for_paths($username, $paths, $avail_lines),
                function ($avail) {
                    return 'unavail' === $avail;
                }));
}


error_reporting(E_ALL | E_STRICT);
date_default_timezone_set('UTC');
putenv("PATH=/usr/local/bin:/usr/bin:/bin");
putenv("LC_ALL=en_US.UTF-8");

$hook = new GitReceiveHook();
$requested_paths = $hook->getReceivedPaths();

if (empty($requested_paths)) {
    deny("We cannot figure out what you comitted!");
}

$avail_lines = $hook->getKarmaFile();
$requested_paths = array_map(function ($x) { return PREFIX . $x;}, $requested_paths);
$unavail_paths = get_unavail_paths($_ENV['REMOTE_USER'], $requested_paths, $avail_lines);

if (!empty($unavail_paths)) {
    deny("You are not allowed to write to " . implode(',', $unavail_paths));
}

accept();
