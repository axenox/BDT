<?php
namespace axenox\BDT\Behat\Common\Traits;

use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Resolves operator-supplied feature paths the same way for every action that starts a Behat run.
 *
 * WHY A TRAIT: RunParallel already knew how to turn the vendor-relative form this framework prints
 * (run log, run_feature.filename, recorded rerun command) back into a real file, while RunTest still
 * checked the raw value against the installation root only. The identical path therefore worked on
 * the RunParallel button and failed on the RunTest button right next to it. Moving the resolver here
 * lets both actions share ONE implementation, so the accepted spellings can never drift apart again.
 *
 * Requires the using class to provide getWorkbench() (every exface action does).
 *
 * WHY method INSTEAD OF AN ABSTRACT DECLARATION: an abstract getWorkbench() in the trait would have
 * to match AbstractAction's signature exactly (PHP 8 enforces trait abstract signatures), so any
 * difference in return type would be a fatal error at class load. The annotation only informs the
 * IDE and static analysis, and adds no runtime contract that could break.
 *
 * @method WorkbenchInterface getWorkbench()
 */
trait FeaturePathResolverTrait
{
    /**
     * Turns a --feature value into an absolute file or directory path on disk.
     *
     * WHY VENDOR IS THE FIRST BASE: this framework prints feature paths in the vendor-relative form
     * (featureRelativePath(), run_feature.filename, the run log), so that is the form an operator
     * copies back into --feature - and it was exactly the form that used to be rejected.
     *
     * WHY THE INSTALLATION ROOT IS STILL A BASE: a path typed relative to the installation root
     * ("vendor/onelink/...") is equally natural and must keep working.
     *
     * @param string $feature Raw --feature value
     * @return string Absolute, existing path
     * @throws RuntimeException if the feature cannot be found under any known base
     */
    private function resolveFeatureScanRoot(string $feature): string
    {
        return $this->resolveExistingPath(
            $feature,
            [
                $this->getWorkbench()->filemanager()->getPathToVendorFolder(),
                $this->getWorkbench()->getInstallationPath()
            ],
            'feature'
        );
    }

    /**
     * Resolves a possibly relative path against a fixed list of candidate bases and returns the first
     * one that exists, as an absolute path.
     *
     * WHY A SHARED HELPER: --feature and --behat_config had the same defect independently - each
     * checked the raw operator value with file_exists()/is_file(), which resolves a relative path
     * against the CWD of the PHP process. That CWD is the installation root for a shell launch but the
     * web root for a launch through the Test Runs console, so the identical command worked in one
     * place and failed in the other.
     *
     * WHY THE BARE VALUE IS TRIED LAST: it is the only candidate whose meaning depends on how the
     * process was started, so it stays a fallback for backwards compatibility rather than the rule.
     *
     * WHY THE CANDIDATES ARE REPORTED: "does not exist" says nothing about where we looked, which is
     * the entire difficulty with a relative path.
     *
     * @param string   $path  Raw operator value (absolute or relative, file or directory)
     * @param string[] $bases Candidate base directories, in priority order
     * @param string   $what  Name of the input, used in the error message
     * @return string Absolute, existing path
     * @throws RuntimeException if none of the candidates exists
     */
    private function resolveExistingPath(string $path, array $bases, string $what): string
    {
        $candidates = [];
        if (FilePathDataType::isAbsolute($path)) {
            $candidates[] = $path;
        } else {
            $relative = ltrim(FilePathDataType::normalize($path, DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR);
            foreach ($bases as $base) {
                $base = rtrim($base, '\\/');
                if ($base !== '') {
                    $candidates[] = $base . DIRECTORY_SEPARATOR . $relative;
                }
            }
            // Last resort: the value as typed, i.e. relative to the CWD of THIS process.
            $candidates[] = $relative;
        }

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                // realpath() collapses "..", "." and symlinks, so the same file always yields the same
                // string - the run log, the DB filename and the worker argument must not drift apart.
                $real = realpath($candidate);
                return $real === false ? $candidate : $real;
            }
        }

        throw new RuntimeException(
            $what . ' does not exist: ' . $path . '. Looked in: ' . implode(', ', $candidates)
        );
    }

    /**
     * Converts an absolute feature file path into its vendor-relative, forward-slashed form.
     *
     * WHY THIS EXISTS: actions carry feature files as absolute paths, because that is what exists on
     * disk. But every place where a path is COMPARED or SHOWN needs the same shortened form that
     * DatabaseFormatter::onBeforeFeature() stores in run_feature.filename. Keeping that normalization
     * in exactly one place means the run-log, the heartbeat key, the recorded rerun command and the DB
     * column can never drift apart.
     *
     * WHY FORWARD SLASHES: the recorded rerun command is injected into the Test Runs console's
     * start_commands JSON array, where a backslash is the escape character and would come back doubled.
     *
     * WHY IT IS NOT LOWER-CASED HERE: this is the human-readable form, so the original spelling of the
     * file is preserved. Case folding is a lookup concern and stays with the caller.
     *
     * @param string $path Absolute feature file path.
     * @return string Vendor-relative, forward-slashed path.
     */
    private function featureRelativePath(string $path) : string
    {
        $normalized = FilePathDataType::normalize($path, '/');
        $vendorPath = FilePathDataType::normalize($this->getWorkbench()->filemanager()->getPathToVendorFolder(), '/') . '/';

        return StringDataType::substringAfter($normalized, $vendorPath, $normalized);
    }
}