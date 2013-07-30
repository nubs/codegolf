<?php
namespace Bizgolf;

function loadLanguage($languageName)
{
    $baseDir = dirname(__DIR__);
    $language = require "{$baseDir}/languages/${languageName}.php";

    list($imageId) = \Hiatus\execX('docker images -q', [$language['tagName']]);
    if ($imageId === '') {
        file_put_contents('php://stderr', "Building image for language {$language['tagName']}.\n");

        $baseDir = dirname(__DIR__);
        \Hiatus\execX('docker build', ['-t' => $language['tagName'], "{$baseDir}/languages/{$language['tagName']}"]);
    }

    return $language;
}

/**
 * Creates a docker image based on the requested language with the given user script added to the image for execution.
 *
 * @param string $languageName One of the supported languages.
 * @param string $script The file path to the user's submission to test.
 * @param string|null $constantName The name of the constant to set, if a constant is being used.
 * @param mixed|null $constantValue The value of the constant to set, if a constant is being used.
 * @return array A description of the docker image that was created.
 * @throws Exception if unable to create docker image
 */
function createImage($languageName, $script, $constantName = null, $constantValue = null)
{
    $image = loadLanguage($languageName);

    $scriptContents = file_get_contents($script);
    if ($scriptContents === false) {
        throw new \Exception('Failed to read user script.');
    }

    if ($constantName !== null) {
        $scriptContents = $image['addConstant']($scriptContents, $constantName, $constantValue);
    }

    return buildImage($image, ['/tmp/userScript' => $scriptContents]);
}

function buildImage($baseImage, array $files, array $commands = [])
{
    list($tempPath) = \Hiatus\execX('mktemp -d');
    $tempPath = trim($tempPath);
    $tempBase = basename($tempPath);

    $dockerCommands = ["FROM {$baseImage['tagName']}"];

    foreach ($files as $fileName => $fileContents) {
        $tempFile = tempnam($tempPath, 'bg');
        if (!file_put_contents($tempFile, $fileContents)) {
            throw new \Exception('Failed to create file in temp directory.');
        }

        $dockerCommands[] = 'ADD ' . basename($tempFile) . " {$fileName}";
    }

    $dockerCommands = array_merge($dockerCommands, $commands);
    if (!file_put_contents("{$tempPath}/Dockerfile", implode("\n", $dockerCommands))) {
        throw new \Exception('Failed to create Dockerfile');
    }

    list($stdout, $stderr) = \Hiatus\execX('docker build', ['-t' => $tempBase, $tempPath]);
    list($imageId) = \Hiatus\execX('docker images -q', [$tempBase]);
    if ($imageId === '') {
        throw new \Exception("Failed to build image.\nOutput: '{$stdout}'\nStderr: '{$stderr}'");
    }

    $baseImage['tagName'] = $tempBase;

    return $baseImage;
}

function execute(array $image)
{
    $tagName = $image['tagName'];
    $executeCommand = $image['executeCommand'];
    file_put_contents('php://stderr',  "Executing script on docker image {$tagName}\n");
    list($containerId) = \Hiatus\execX('docker run -d', [$tagName, $executeCommand, '/tmp/userScript']);
    $containerId = trim($containerId);

    list($exitStatus) = \Hiatus\execX('docker wait', [$containerId], 10);
    $exitStatus = trim($exitStatus);
    $exitStatus = is_numeric($exitStatus) ? (int)$exitStatus : null;

    list($output, $stderr) = \Hiatus\execX('docker logs', [$containerId]);

    return ['exitStatus' => $exitStatus, 'output' => $output, 'stderr' => $stderr];
}

/**
 * Loads the hole configuration for an included hole.  If you want to add your own holes outside of this project, you don't need to call this
 * function.
 *
 * @param string $holeName One of the included holes.
 * @return array The hole's configuration.  Included fields:
 *     string|null constantName The name of the constant that will hold input.
 *         This may be a callable as well, with 0 arguments.
 *     array constantValues The different values of input to test.
 *         This may be a callable as well, with 0 arguments.
 *     callable|null trim What kind of trim to apply to the results before comparison.
 *     string sample The expected output for the hole.
 *         This may be a callable as well, with 1 argument containing the constant value for input.
 */
function loadHole($holeName)
{
    $baseDir = dirname(__DIR__);

    $hole = require "{$baseDir}/holes/${holeName}.php";

    return $hole + ['constantName' => null, 'constantValues' => [], 'disableFunctionality' => [], 'trim' => null];
}

/**
 * Judges the user submission for a language against the given hole configuration.
 *
 * @param array $hole The hole's configuration.  @see loadHole() for details.
 * @param string $languageName One of the supported languages.
 * @param string $script The file path to the user's submission to test.
 * @return array The results of judging the submission and the details of the submission's last run.  Included fields:
 *     bool result Whether the submission passed the tests or not.
 *     int exitStatus The exit status of the command.
 *     string output The output, trimmed according to the rules of the hole.
 *     string sample The expected output, trimmed according to the rules of the hole.
 *     string stderr The stderr output.
 *     string|null constantName The constant's name, if used.
 *     mixed|null constantValue The constant's value, if used.
 */
function judge($hole, $languageName, $script)
{
    $executeAndJudge = function($constantName = null, $constantValue = null) use($hole, $languageName, $script) {
        $sample = is_callable($hole['sample']) ? call_user_func($hole['sample'], $constantValue) : $hole['sample'];

        $image = createImage($languageName, $script, $constantName, $constantValue);
        foreach($hole['disableFunctionality'] as $functionality) {
            $image = $image['disableFunctionality']($image, $functionality);
        }

        $result = execute($image) + ['constantName' => $constantName, 'constantValue' => $constantValue, 'sample' => $sample];

        if ($hole['trim'] !== null) {
            $result['output'] = $hole['trim']($result['output']);
            $result['sample'] = $hole['trim']($result['sample']);
        }

        return $result + ['result' => $result['exitStatus'] === 0 && $result['output'] === $result['sample']];
    };

    $constantName = $hole['constantName'];
    if ($constantName !== null) {
        $constantValues = is_callable($hole['constantValues']) ? call_user_func($hole['constantValues']) : $hole['constantValues'];
        foreach ($constantValues as $constantValue) {
            $result = $executeAndJudge($constantName, $constantValue);
            if (!$result['result']) {
                return $result;
            }
        }

        return $result;
    } else {
        return $executeAndJudge();
    }
}
