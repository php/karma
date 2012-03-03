<?php
namespace Git;

class PreReceiveHook extends ReceiveHook
{
    const INPUT_PATTERN = '@^([0-9a-f]{40}) ([0-9a-f]{40}) (.+)$@i';

    private $karmaFile;

    public function __construct($karma_file, $base_path)
    {
        parent::__construct($base_path);
        $this->karmaFile = $karma_file;
    }

    /**
    * Returns true if git option karma.ignored is set, otherwise false.
    *
    * @return boolean
    */
    public function isKarmaIgnored()
    {
        return 'true' === exec(sprintf('%s config karma.ignored', \Git::GIT_EXECUTABLE));
    }

    public function mapInput(callable $fn) {
        $result = [];
        foreach($this->hookInput() as $input) {
            $result[] = $fn($input['old'], $input['new']);
        }

        return $result;
    }

    /**
    * Return the content of the karma file from the karma repository.
    *
    * We read the content of the karma file from the karma repository index.
    *
    * @return string
    */
    public function getKarmaFile()
    {
        return file($this->karmaFile);
    }

    /**
    * Returns an array of files that were updated between revision $old and $new.
    *
    * @param string $old The old revison number.
    * @parma string $new The new revison umber.
    *
    * @return array
    */
    private function getReceivedPathsForRange($old, $new)
    {
        $repourl = \Git::getRepositoryPath();
        $output  = [];

        /* there is the case where we push a new branch. check only new commits.
          in case its a brand new repo, no heads will be available. */
        if ($old == \Git::NULLREV) {
            exec(
                sprintf("%s --git-dir=%s for-each-ref --format='%%(refname)' 'refs/heads/*'",
                    \Git::GIT_EXECUTABLE, $repourl), $output);
            /* do we have heads? otherwise it's a new repo! */
            $heads = implode(' ', $output);
            if (count($output) > 0) {
                $not = array_map(
                    function($x) {
                        return sprintf('--not %s', escapeshellarg($x));
                    }, $heads);
            }
            exec(
                sprintf('%s --git-dir=%s log --name-only --pretty=format:"" %s %s',
                \Git::GIT_EXECUTABLE, $repourl, $not,
                escapeshellarg($new)), $output);
            } else {
            exec(
                sprintf('%s --git-dir=%s log --name-only --pretty=format:"" %s..%s',
                \Git::GIT_EXECUTABLE, $repourl, escapeshellarg($old),
                escapeshellarg($new)), $output);
            }
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
