<?php
require_once __DIR__ . '/../src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

// Simulate the env-check logic directly
$checks = [];

// Python
$pyOut = [];
exec('python3 --version 2>&1', $pyOut, $pyRet);
$pythonVersion = $pyRet === 0 ? trim(implode(' ', $pyOut)) : null;
if (!$pythonVersion) {
    exec('python --version 2>&1', $pyOut2, $pyRet2);
    $pythonVersion = $pyRet2 === 0 ? trim(implode(' ', $pyOut2)) : null;
}
$checks['python'] = ['available' => $pythonVersion !== null, 'version' => $pythonVersion];

// ffmpeg
$ffOut = [];
exec('ffmpeg -version 2>&1 | head -1', $ffOut, $ffRet);
$ffVersion = ($ffRet === 0 && !empty($ffOut)) ? trim($ffOut[0]) : null;
$checks['ffmpeg'] = ['available' => $ffVersion !== null, 'version' => $ffVersion];

// Whisper
$wOut = [];
exec('python3 -c "import whisper; print(whisper.__version__)" 2>&1', $wOut, $wRet);
$checks['whisper'] = ['available' => $wRet === 0, 'version' => $wRet === 0 ? trim($wOut[0]) : null];

// pymysql
$pmOut = [];
exec('python3 -c "import pymysql; print(pymysql.__version__)" 2>&1', $pmOut, $pmRet);
$checks['pymysql'] = ['available' => $pmRet === 0, 'version' => $pmRet === 0 ? trim($pmOut[0]) : null];

// Disk
$diskFree = @disk_free_space('/volume1/') ?: 0;
$checks['disk'] = ['available' => $diskFree > 0, 'free_gb' => round($diskFree / 1073741824, 1)];

// CPU
$cpuOut = [];
exec('nproc 2>/dev/null', $cpuOut, $cpuRet);
$checks['cpu'] = ['available' => $cpuRet === 0, 'cores' => $cpuRet === 0 ? (int)trim($cpuOut[0]) : null];

// Scripts
$toolsDir = realpath(__DIR__ . '/../tools');
$checks['scripts'] = [
    'manager'  => file_exists($toolsDir . '/transcription_manager.py'),
    'recorder' => file_exists($toolsDir . '/transcription_recorder.py'),
    'worker'   => file_exists($toolsDir . '/transcription_worker.py'),
    'tools_dir' => $toolsDir,
];

echo json_encode(['checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
