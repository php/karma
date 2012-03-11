<?php
namespace Git;

class PreReceiveHook extends ReceiveHook
{

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

    public function getReceivedPaths()
    {
        $parsed_input = $this->hookInput();

        // escaped branches
        $allBranches =$this->escapeArrayShellArgs($this->getAllBranches());

        $paths = array_map(
            function ($input) use ($allBranches) {
                $paths = [];

                if ($input['changetype'] == self::TYPE_CREATED) {
                    $paths = $this->getChangedPaths(escapeshellarg($input['new']) . ' --not ' . implode(' ', $allBranches));
                } elseif ($input['changetype'] == self::TYPE_UPDATED) {
                    $paths = $this->getChangedPaths(escapeshellarg($input['old'] . '..' . $input['new']));
                } else {
                    // deleted branch. we also need some paths
                    // to check karma
                }

                return array_keys($paths);
            },
           $parsed_input);

        /* flattern the array */
        $flattend = array_reduce($paths, 'array_merge', []);


        return array_unique($paths);
    }
}
