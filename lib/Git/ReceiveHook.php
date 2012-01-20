<?php
namespace Git;

class ReceiveHook
{
    const GIT_EXECUTABLE = 'git';
    const INPUT_PATTERN = '@^([0-9a-f]{40}) ([0-9a-f]{40}) (.+)$@i';

    private $karmaFile;
    private $repositoryBasePath;

    public function __construct($karma_file, $base_path)
    {
        $this->karmaFile = $karma_file;
        $this->repositoryBasePath = $base_path;
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
        $rel_path = str_replace($this->repositoryBasePath, '', $this->getRepositoryPath());
        if (preg_match('@/(.*\.git)$@', $rel_path, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Returns the path to the current repository.
     *
     * Tries to determine the path of the current repository in which
     * the hook was invoked.
     *
     * @return string
     */
    public function getRepositoryPath()
    {
        $path = exec(sprintf('%s rev-parse --git-dir', self::GIT_EXECUTABLE));
        if (!is_dir($path)) {
            return false;
        }

        return realpath($path);
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
        static $parsed_input = [];
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
        $repourl = $this->getRepositoryPath();
        $output  = [];

        /* there is the case where we push a new branch. check only new commits.
           in case its a brand new repo, no heads will be available. */
        if ($old == '0000000000000000000000000000000000000000') {
            exec(
                sprintf("%s --git-dir=%s for-each-ref --format='%%(refname)' 'refs/heads/*'",
                    self::GIT_EXECUTABLE, $repourl), $output);
            /* do we have heads? otherwise it's a new repo! */
            $heads = implode(' ', $output);
            $not   = count($output) > 0 ? sprintf('--not %s', $heads) : '';
            exec(
                sprintf('%s --git-dir=%s log --name-only --pretty=format:"" %s %s',
                self::GIT_EXECUTABLE, $repourl, escapeshellarg($not),
                escapeshellarg($new), $output);
        } else {
            exec(
                sprintf('%s --git-dir=%s log --name-only --pretty=format:"" %s..%s',
                self::GIT_EXECUTABLE, $repourl, escapeshellarg($old),
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
