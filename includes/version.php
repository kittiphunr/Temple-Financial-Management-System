<?php
/**
 * เลขเวอร์ชันและหมายเลขบิวล์ของระบบ
 * เวอร์ชันเป็นแบบ MAJOR.MINOR.PATCH อ่านจากไฟล์ VERSION (แก้ไขเล็กน้อย = เพิ่มหลักที่ 3 ทีละ 1 · อัปเดตใหญ่ = เพิ่มหลักที่ 2 แล้วหลักที่ 3 กลับเป็น 0)
 * หมายเลขบิวล์สร้างตอนทำชุดติดตั้ง (deploy/build.sh) เก็บใน version.json ในชุด = จำนวนคอมมิตสะสม ณ ตอนบิวล์ ถ้าไม่มีไฟล์นี้ (รันจากซอร์สตรง ๆ) ถือเป็นบิวล์ "dev"
 * @return array{version:string,build:?int,commit:string,built_at:string,label:string}
 */
function app_version(): array
{
    static $v = null;
    if ($v !== null) {
        return $v;
    }
    $root = dirname(__DIR__);
    $version = '0.0.0';
    $build = null;
    $commit = '';
    $builtAt = '';
    $raw = @file_get_contents($root . '/VERSION');
    if ($raw !== false && preg_match('/^\d+\.\d+\.\d+$/', trim($raw))) {
        $version = trim($raw);
    }
    $j = @file_get_contents($root . '/version.json');
    if ($j !== false && is_array($m = json_decode($j, true))) {
        if (isset($m['version']) && preg_match('/^\d+\.\d+\.\d+$/', (string)$m['version'])) {
            $version = (string)$m['version'];
        }
        $build = isset($m['build']) && (int)$m['build'] > 0 ? (int)$m['build'] : null;
        $commit = preg_match('/^[0-9a-f]{4,40}$/', (string)($m['commit'] ?? '')) ? (string)$m['commit'] : '';
        $builtAt = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)($m['built_at'] ?? '')) ? substr((string)$m['built_at'], 0, 10) : '';
    }
    $label = 'เวอร์ชัน ' . $version . ($build ? ' (บิวล์ ' . $build . ($commit ? ' · ' . $commit : '') . ')' : ' (dev)');
    return $v = ['version' => $version, 'build' => $build, 'commit' => $commit, 'built_at' => $builtAt, 'label' => $label];
}
