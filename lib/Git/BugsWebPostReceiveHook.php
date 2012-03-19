<?php
namespace Git;

class BugsWebPostReceiveHook extends ReceiveHook
{

    public function getReceivedMessages()
    {
        $this->hookInput();

        $paths = array_map(
            function ($input) {
                return $this->getReceivedMessagesForRange($input['old'], $input['new']);
            },
            $this->refs);

        /* remove empty lines, and flattern the array */
        $flattend = array_reduce($paths, 'array_merge', []);
        $paths    = array_filter($flattend);

        return array_unique($paths);
    }

    /**
     * Returns an array of commit messages between revision $old and $new.
     *
     * @param string $old The old revison number.
     * @parma string $new The new revison umber.
     *
     * @return array
     */
    private function getReceivedMessagesForRange($old, $new)
    {
        $repourl = \Git::getRepositoryPath();
        $output = [];

        if ($old == '0000000000000000000000000000000000000000') {
            $cmd = sprintf(
                "%s --git-dir=%s for-each-ref --format='%%(refname)' 'refs/heads/*'",
                self::GIT_EXECUTABLE,
                $repourl
            );
            exec($cmd, $output);

            /* do we have heads? otherwise it's a new repo! */
            $heads = implode(' ', $output);
            $not   = count($output) > 0 ? sprintf('--not %s', escapeshellarg($heads)) : '';
            $cmd   = sprintf(
                '%s --git-dir=%s log --pretty=format:"[%%ae] %%H %%s" %s %s',
                \Git::GIT_EXECUTABLE,
                $repourl,
                $not,
                escapeshellarg($new)
            );
            exec($cmd, $output);
        } else {
            $cmd = sprintf(
                '%s --git-dir=%s log --pretty=format:"[%%ae] %%H %%s" %s..%s',
                \Git::GIT_EXECUTABLE,
                $repourl,
                escapeshellarg($old),
                escapeshellarg($new)
            );
            exec($cmd, $output);
        }

        return $output;
    }
}