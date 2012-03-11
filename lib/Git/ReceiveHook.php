<?php
namespace Git;

abstract class ReceiveHook
{
    const INPUT_PATTERN = '@^([0-9a-f]{40}) ([0-9a-f]{40}) (.+)$@i';

    const TYPE_UPDATED = 0;
    const TYPE_CREATED = 1;
    const TYPE_DELETED = 2;

    const REF_BRANCH = 0;
    const REF_TAG = 1;

    private $repositoryName = '';
    protected $repositoryPath = '';

    public function __construct($basePath)
    {
        $this->repositoryPath = \Git::getRepositoryPath();
        $rel_path = str_replace($basePath, '', $this->repositoryPath);
        if (preg_match('@/(.*\.git)$@', $rel_path, $matches)) {
            $this->repositoryName = $matches[1];
        }
    }

    /**
     * Run git shell command and return result
     *
     * @param $cmd string
     * @return string
     */
    protected function gitExecute($cmd)
    {
        $cmd = \Git::GIT_EXECUTABLE . " --git-dir=" . $this->repositoryPath . " " . $cmd;
        $args = func_get_args();
        array_shift($args);
        $cmd = vsprintf($cmd, $args);
        $output = shell_exec($cmd);
        return $output;
    }

    /**
     * Escape array items by escapeshellarg function
     * @param $args
     * @return array array with escaped items
     */
    protected function escapeArrayShellArgs($args)
    {
        return array_map('escapeshellarg', $args);
    }


    /**
     * Returns the repository name.
     *
     * A repository name is the path to the repository without the .git.
     * e.g. php-src.git -> php-src
     *
     * @return string
     */
    public function getRepositoryName()
    {
        return $this->repositoryName;
    }

    /**
     * Return array with changed paths as keys and change type as values
     * If commit is merge commit change type will have more than one char
     * (for example "MM")
     * @param $revRange
     * @return array
     */
    protected function getChangedPaths($revRange)
    {
        $raw = $this->gitExecute('show --name-status --pretty="format:" %s', $revRange);
        $paths = [];
        if (preg_match_all('/([ACDMRTUXB*]+)\s+([^\n\s]+)/', $raw , $matches,  PREG_SET_ORDER)) {
            foreach($matches as $item) {
                $paths[$item[2]] = $item[1];
            }
        }
        return $paths;
    }


    /**
     * Return array with branches names in repository
     *
     * @return array
     */
    protected function getAllBranches()
    {
        $branches = explode("\n", $this->gitExecute('for-each-ref --format="%%(refname)" "refs/heads/*"'));
        if ($branches[0] == '') $branches = [];
        return $branches;
    }


    /**
     * Parses the input from git.
     *
     * Git pipes a list of oldrev, newrev and revname combinations
     * to the hook. We parse this input. For more information about
     * the input see githooks(5).
     *
     * Returns an array with 'old', 'new', 'refname', 'changetype', 'reftype'
     * keys for each ref that will be updated.
     * @return array
     */
    public function hookInput()
    {
        $parsed_input = [];
        while (!feof(STDIN)) {
            $line = fgets(STDIN);
            if (preg_match(self::INPUT_PATTERN, $line, $matches)) {

                $ref = [
                    'old'     => $matches[1],
                    'new'     => $matches[2],
                    'refname' => $matches[3]
                ];

                if (preg_match('~^refs/heads/.+$~', $ref['refname'])) {
                    // git push origin branchname
                    $ref['reftype'] = self::REF_BRANCH;
                } elseif (preg_match('~^refs/tags/.+$~', $ref['refname'])) {
                    // git push origin tagname
                    $ref['reftype'] = self::REF_TAG;
                } else {
                    // not support by this script
                    $ref['reftype'] = null;
                }

                if ($ref['old'] == \GIT::NULLREV) {
                    // git branch branchname && git push origin branchname
                    // git tag tagname rev && git push origin tagname
                    $ref['changetype'] = self::TYPE_CREATED;
                } elseif ($ref['new'] == \GIT::NULLREV) {
                    // git branch -d branchname && git push origin :branchname
                    // git tag -d tagname && git push origin :tagname
                    $ref['changetype'] =  self::TYPE_DELETED;
                } else {
                    // git push origin branchname
                    // git tag -f tagname rev && git push origin tagname
                    $ref['changetype'] =  self::TYPE_UPDATED;
                }


                $parsed_input[$ref['refname']] = $ref;
            }
        }
        return $parsed_input;
    }
}
