<?php

namespace App\Utils;

class Functions
{

    /**
     * Extract invoice XML and optional ANAF signature from a ZIP archive.
     *
     * @return array{xml: string|false, signature: string|null}
     */
    public static function unzip($zipData, int $defaultIndex = 0): array
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'anaf-invoice');
        file_put_contents($tempFile, $zipData);

        $zip = new \ZipArchive();
        $zip->open($tempFile);

        $xml = false;
        $signature = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                continue;
            }

            if (preg_match('/CustomizationID/is', $content)) {
                $xml = $content;
            } else {
                // The other file in the ZIP is the detached signature
                $signature = $content;
            }
        }

        // Fallback: if no file matched CustomizationID, use original index logic
        if ($xml === false) {
            $xml = $zip->getFromIndex($defaultIndex);
            if ($xml !== false && !preg_match('/CustomizationID/is', $xml)) {
                $xml = $zip->getFromIndex($defaultIndex + 1);
            }
        }

        $zip->close();
        unlink($tempFile);

        return ['xml' => $xml, 'signature' => $signature];
    }
    public static function displayRelativeTime(int $timestamp)
    {
        $currentTime = time();
        $timeDifference = $timestamp - $currentTime;

        $seconds = $timeDifference;
        $minutes = round($seconds / 60);
        $hours = round($seconds / 3600);
        $days = round($seconds / 86400);
        $weeks = round($seconds / 604800);
        $months = round($seconds / 2629440);
        $years = round($seconds / 31553280);

        if ($seconds <= 60) {
            return 'în câteva secunde';
        } elseif ($minutes <= 60) {
            return ($minutes == 1) ? 'într-un minut' : "în $minutes minute";
        } elseif ($hours <= 24) {
            return ($hours == 1) ? 'într-o oră' : "în $hours ore";
        } elseif ($days <= 7) {
            return ($days == 1) ? 'mâine' : "în $days zile";
        } elseif ($weeks <= 4.3) {
            return ($weeks == 1) ? 'săptămâna viitoare' : "în $weeks săptămâni";
        } elseif ($months <= 12) {
            return ($months == 1) ? 'într-o lună' : "în $months luni";
        } else {
            return ($years == 1) ? 'într-un an' : "în $years ani";
        }
    }
}
