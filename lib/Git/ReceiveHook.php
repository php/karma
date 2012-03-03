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

    public function __construct($basePath)
    {
        $rel_path = str_replace($basePath, '', \Git::getRepositoryPath());
        if (preg_match('@/(.*\.git)$@', $rel_path, $matches)) {
            $this->repositoryName = $matches[1];
        }
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
     * Parses the input from git.
     *
     * Git pipes a list of oldrev, newrev and revname combinations
     * to the hook. We parse this input. For more information about
     * the input see githooks(5).
     *
     * Returns an array with 'old', 'new', 'refname' keys for each ref that
     * will be updated.
     * @return array
     */
    public function hookInput()
    {
        $parsed_input = array();
        while (!feof(STDIN)) {
            $line = fgets(STDIN);
            if (preg_match(self::INPUT_PATTERN, $line, $matches)) {

                $ref = array(
                    'old'     => $matches[1],
                    'new'     => $matches[2],
                    'refname' => $matches[3]
                );

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


                $parsed_input[] = $ref;
            }
        }
        return $parsed_input;
    }
}
