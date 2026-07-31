<?php
/**
 * theme-health.php — ตรวจสุขภาพธีม WordPress ทุกเว็บบนเซิร์ฟเวอร์
 *                    (หาโฟลเดอร์ธีมที่ว่างเปล่า + นับไฟล์/ขนาด + เช็ค style.css หลัก)
 *
 * อ่านอย่างเดียว 100% — ไม่ลบ ไม่แก้ ไม่ย้ายไฟล์ใด ๆ
 *
 * รันตรงจาก GitHub (ไม่ต้องเซฟไฟล์):
 *   URL='https://raw.githubusercontent.com/ufavisionseoteam19/theme-health-check/main/theme-health.php'
 *   curl -s "$URL?v=$(date +%s)" | php                          # สแกนทั้งเครื่อง
 *   curl -s "$URL?v=$(date +%s)" | php -- --user=newkeydec2025  # เฉพาะบัญชีเดียว
 *   curl -s "$URL?v=$(date +%s)" | php -- --only-issues         # เฉพาะที่มีปัญหา
 *   curl -s "$URL?v=$(date +%s)" | php -- --csv                 # ออกผลเป็น CSV
 *
 * Flags:
 *   --user=NAME       สแกนเฉพาะบัญชีผู้ใช้นี้ (ค่าเริ่มต้น = ทุกบัญชีใน /home)
 *   --base=PATH       เปลี่ยนโฟลเดอร์ฐาน (ค่าเริ่มต้น = /home)
 *   --threshold=N     ธีมที่มีไฟล์ <= N ถือว่าน่าสงสัย (ค่าเริ่มต้น = 1)
 *                     (ตั้งต่ำเพราะ child theme ปกติมีแค่ 3 ไฟล์: style.css, functions.php, screenshot)
 *   --maxdepth=N      จำกัดความลึกการค้นจาก base (ค่าเริ่มต้น = 4)
 *   --exclude=a,b,c   ยกเว้นธีมเพิ่ม (คั่นด้วยคอมมา)
 *   --csv             แสดงผลเป็น CSV (เอาไป import / เก็บ log ได้)
 *   --only-issues     แสดงเฉพาะตัวที่ว่าง/น่าสงสัย (ซ่อนตัวปกติ)
 *   --no-progress     ปิดข้อความความคืบหน้า
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

// ====== รันแบบถ่อมตัว: ลด priority CPU เพื่อไม่ไปแย่งทรัพยากรเว็บอื่น ======
if (function_exists('proc_nice')) { @proc_nice(19); }
@exec('ionice -c3 -p ' . getmypid() . ' 2>/dev/null');

// ====== URL ของสคริปต์ตัวเองบน GitHub ======
const SELF_URL = 'https://raw.githubusercontent.com/ufavisionseoteam19/theme-health-check/main/theme-health.php';

// ====== ค่าตั้งต้น ======
$BASE        = '/home';
$ONLY_USER   = null;
$THRESHOLD   = 10;    // ธีมทั่วไป: มีไฟล์ <= ค่านี้ = สงสัย
$CHILD_NAME  = 'blocksy-child';  // child theme หลัก
$CHILD_MIN   = 2;     // blocksy-child: มีไฟล์ <= ค่านี้ = สงสัย (ปกติมี 3 ไฟล์)
$PARENT_NAME = 'blocksy';  // ชื่อ parent theme หลักที่ตรวจเข้ม
$PARENT_MIN  = 50;    // parent theme ที่มีไฟล์ <= ค่านี้ = น่าสงสัย (ไฟล์หายเยอะ)
$AS_CSV      = false;
$ONLY_ISSUES = false;
$MAXDEPTH    = 4;
$PROGRESS    = true;
$EXCLUDE     = [];

// ====== อ่าน argument ======
global $argv;
foreach ($argv as $a) {
    if (strpos($a, '--user=')      === 0) { $ONLY_USER  = substr($a, 7); }
    elseif (strpos($a, '--base=')  === 0) { $BASE       = substr($a, 7); }
    elseif (strpos($a, '--threshold=') === 0) { $THRESHOLD = (int)substr($a, 12); }
    elseif (strpos($a, '--maxdepth=')  === 0) { $MAXDEPTH  = (int)substr($a, 11); }
    elseif (strpos($a, '--exclude=')   === 0) {
        foreach (explode(',', substr($a, 10)) as $e) {
            $e = trim($e);
            if ($e !== '') { $EXCLUDE[] = $e; }
        }
    }
    elseif ($a === '--csv')         { $AS_CSV = true; }
    elseif ($a === '--only-issues') { $ONLY_ISSUES = true; }
    elseif ($a === '--no-progress') { $PROGRESS = false; }
}
$EXCLUDE = array_unique($EXCLUDE);
if ($AS_CSV) { $PROGRESS = false; }

// ====== ฟังก์ชันช่วย ======

/** นับจำนวนไฟล์ในโฟลเดอร์ (recursive) */
function count_files($dir) {
    $n = 0;
    $it = @new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    if ($it === false) return 0;
    foreach ($it as $f) { if ($f->isFile()) $n++; }
    return $n;
}

/** ขนาดรวมของโฟลเดอร์ (recursive) เป็น byte */
function dir_size($dir) {
    $size = 0;
    $it = @new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    if ($it === false) return 0;
    foreach ($it as $f) { if ($f->isFile()) $size += $f->getSize(); }
    return $size;
}

/** แปลง byte เป็นข้อความอ่านง่าย */
function human_size($b) {
    if ($b >= 1073741824) return round($b/1073741824, 1) . 'G';
    if ($b >= 1048576)    return round($b/1048576, 1) . 'M';
    if ($b >= 1024)       return round($b/1024, 1) . 'K';
    return $b . 'B';
}

/** ตรวจว่าธีมมีไฟล์ style.css หลักที่มี WordPress Theme header หรือไม่
 *  ธีมแท้ต้องมี style.css ที่มีบรรทัด "Theme Name:"
 *  ถ้าไม่มี = ไฟล์หลักหาย/เสียหาย แม้จะมีไฟล์อื่นเยอะก็ใช้งานไม่ได้
 *  คืนค่า: true = มี header ครบ, false = ไม่พบไฟล์หลัก */
function has_theme_header($dir) {
    $css = "$dir/style.css";
    if (!is_file($css)) return false;
    $head = @file_get_contents($css, false, null, 0, 8192);
    if ($head === false) return false;
    return (stripos($head, 'Theme Name:') !== false);
}

/** โฟลเดอร์ "ซากสำรอง/เปลี่ยนชื่อทิ้ง" — ไม่ใช่ธีมที่ใช้งานจริง จึงข้ามไม่นับเป็นปัญหา
 *  เช่น blocksy_broken_20260603, blocksy.bak, theme-old, twentytwenty_backup
 *  (กดซ่อมไม่ได้อยู่แล้วเพราะไม่มีธีมชื่อนี้จริง — เป็นแค่ของที่คนซ่อมเปลี่ยนชื่อทิ้งไว้) */
function is_backup_leftover($name) {
    $n = strtolower($name);
    if (preg_match('/(^|[._-])(broken|bak|backup|old|orig|disabled?|copy|save|tmp|temp|unused)([._-]|$)/', $n)) return true;
    if (preg_match('/[._-]\d{6,8}$/', $n)) return true;            // ลงท้ายด้วยวันที่ เช่น _20260603
    if (preg_match('/[._-]\d{4}-\d{2}-\d{2}$/', $n)) return true;  // เช่น _2026-06-03
    return false;
}

/** เช็คไฟล์หลักที่จำเป็นของ parent theme (blocksy)
 *  parent theme เต็มต้องมี: style.css, index.php, functions.php
 *  คืนค่า: array ของไฟล์ที่ "ขาด" (ว่าง = ครบทุกไฟล์) */
function missing_core_files($dir) {
    $required = ['style.css', 'index.php', 'functions.php'];
    $missing = [];
    foreach ($required as $f) {
        if (!is_file("$dir/$f")) $missing[] = $f;
    }
    return $missing;
}

// ====== หาโฟลเดอร์ wp-content/themes ======
// ค้นแบบ recursive แต่จำกัดความลึก ($MAXDEPTH นับจาก $BASE) เพื่อความเร็ว
// ความลึก 4 ครอบคลุม: /home/user/public_html/, /home/user/domain/,
// /home/user/domains/x/public_html/ ฯลฯ

/** ค้นหาโฟลเดอร์ที่ลงท้ายด้วย wp-content/themes */
function find_theme_dirs($root, $maxdepth, $progress) {
    $found = [];
    $skip_names = ['node_modules', '.git', 'cache', '.cache', 'tmp', 'logs'];
    $stack = [[$root, 0]];
    while ($stack) {
        list($dir, $depth) = array_pop($stack);
        $dh = @opendir($dir);
        if ($dh === false) continue;
        while (($entry = readdir($dh)) !== false) {
            if ($entry === '.' || $entry === '..') continue;
            // ข้ามโฟลเดอร์ซ่อน/ระบบที่ขึ้นต้นด้วยจุด (เช่น .cpanel/ea-php-cli ที่ cPanel
            // สร้าง "เงา" wp-content/themes ไว้ มีแต่ไฟล์ cache → เคยตรวจเจอ "ธีมว่าง" หลอก)
            if (substr($entry, 0, 1) === '.') continue;
            $path = "$dir/$entry";
            if (!is_dir($path) || is_link($path)) continue;
            // เจอ wp-content/themes → เก็บ แล้วไม่ไต่ลงต่อ
            if ($entry === 'themes' && substr($dir, -11) === '/wp-content') {
                $found[] = $path;
                if ($progress && count($found) % 200 === 0) {
                    fwrite(STDERR, "  ...พบแล้ว " . count($found) . " ไซต์\r");
                }
                continue;
            }
            // wp-content ต้องไต่ต่อเสมอ (themes อยู่ข้างใน)
            if ($entry === 'wp-content') { $stack[] = [$path, $depth]; continue; }
            if (in_array($entry, $skip_names, true)) continue;
            if ($depth + 1 <= $maxdepth) { $stack[] = [$path, $depth + 1]; }
        }
        closedir($dh);
    }
    if ($progress && count($found) > 0) fwrite(STDERR, "                              \r");
    return $found;
}

if ($ONLY_USER) {
    $scan_root = "$BASE/$ONLY_USER";
} else {
    $scan_root = $BASE;
}
if ($PROGRESS) fwrite(STDERR, "กำลังค้นหาไซต์ WordPress (ลึกไม่เกิน $MAXDEPTH ชั้น)...\n");
$theme_dirs = is_dir($scan_root) ? find_theme_dirs($scan_root, $MAXDEPTH, $PROGRESS) : [];
sort($theme_dirs);

// ====== ส่วนหัว ======
if (!$AS_CSV) {
    echo "=======================================================\n";
    echo " WordPress Theme Health Check (PHP, read-only)\n";
    echo "=======================================================\n";
    echo " วันเวลา      : " . date('Y-m-d H:i:s') . "\n";
    echo " เครื่อง       : " . php_uname('n') . "\n";
    echo " โฟลเดอร์ฐาน  : $BASE\n";
    echo " ขอบเขต       : " . ($ONLY_USER ? "เฉพาะบัญชี '$ONLY_USER'" : "ทุกบัญชีใน $BASE") . "\n";
    echo " เกณฑ์น่าสงสัย: ธีมทั่วไป <= $THRESHOLD | $CHILD_NAME <= $CHILD_MIN\n";
    echo " เกณฑ์ parent : $PARENT_NAME ต้องมีไฟล์หลักครบ + ไฟล์ > $PARENT_MIN\n";
    echo " ความลึกค้นหา : ไม่เกิน $MAXDEPTH ชั้น\n";
    echo " ยกเว้น       : " . (count($EXCLUDE) ? implode(', ', $EXCLUDE) : '(ไม่มี)') . "\n";
    echo " พบ WordPress : " . count($theme_dirs) . " ไซต์\n\n";
} else {
    echo "site,theme,files,size_bytes,status\n";
}

if (count($theme_dirs) === 0) {
    if (!$AS_CSV) echo "ไม่พบโฟลเดอร์ wp-content/themes ใด ๆ ภายใต้ $scan_root\n";
    exit(0);
}

// ====== ตัวนับสรุป ======
$total_themes  = 0;
$total_empty   = 0;
$total_suspect = 0;
$total_nohdr   = 0;
$total_core    = 0;
$empty_list    = [];
$suspect_list  = [];
$nohdr_list    = [];
$core_list     = [];   // parent theme ที่ไฟล์หลัก 3 ตัวไม่ครบ

// ====== วนแต่ละไซต์ ======
foreach ($theme_dirs as $tdir) {
    $site = preg_replace('#/wp-content/themes$#', '', $tdir);

    if (!$AS_CSV) {
        echo "-------------------------------------------------------\n";
        echo " ไซต์: $site\n";
        echo "-------------------------------------------------------\n";
        printf("  %-8s %-7s %-9s %s\n", 'สถานะ', 'ไฟล์', 'ขนาด', 'ธีม');
    }

    $entries = glob("$tdir/*", GLOB_ONLYDIR);
    sort($entries);

    foreach ($entries as $d) {
        $name = basename($d);

        if (in_array($name, $EXCLUDE, true)) {
            if (!$AS_CSV && !$ONLY_ISSUES) {
                printf("  %-8s %-7s %-9s %s\n", 'ข้าม', '-', '-', $name);
            }
            continue;
        }

        // โฟลเดอร์ซากสำรอง/เปลี่ยนชื่อทิ้ง → ข้าม ไม่นับเป็นปัญหา (กันแจ้งผิด + กดซ่อมไม่ได้)
        if (is_backup_leftover($name)) {
            if (!$AS_CSV && !$ONLY_ISSUES) {
                printf("  %-8s %-7s %-9s %s\n", 'ข้าม(สำรอง)', '-', '-', $name);
            }
            continue;
        }

        $total_themes++;
        $fcount = count_files($d);
        $bytes  = dir_size($d);
        $size   = human_size($bytes);

        $is_parent = ($name === $PARENT_NAME);  // blocksy ตัวแม่
        $is_child  = ($name === $CHILD_NAME);   // blocksy-child

        if ($fcount === 0) {
            $status = 'ว่าง!';
            $total_empty++;
            $empty_list[] = ['theme' => $name, 'site' => $site, 'files' => $fcount];
        } elseif ($is_parent) {
            // ===== blocksy (แม่): ตรวจเข้ม 2 ชั้น =====
            $missing = missing_core_files($d);
            if (!empty($missing)) {
                $status = 'ไฟล์หลักขาด';
                $total_core++;
                $core_list[] = ['theme' => "$name (ขาด: " . implode(',', $missing) . ")", 'site' => $site, 'files' => $fcount];
            } elseif ($fcount <= $PARENT_MIN) {
                $status = 'สงสัย';
                $total_suspect++;
                $suspect_list[] = ['theme' => "$name (parent ไฟล์น้อย)", 'site' => $site, 'files' => $fcount];
            } else {
                $status = 'ปกติ';
            }
        } elseif ($is_child) {
            // ===== blocksy-child: เกณฑ์ <= 2 ไฟล์ =====
            if ($fcount <= $CHILD_MIN) {
                $status = 'สงสัย';
                $total_suspect++;
                $suspect_list[] = ['theme' => $name, 'site' => $site, 'files' => $fcount];
            } elseif (!has_theme_header($d)) {
                $status = 'ไม่มีหลัก';
                $total_nohdr++;
                $nohdr_list[] = ['theme' => $name, 'site' => $site, 'files' => $fcount];
            } else {
                $status = 'ปกติ';
            }
        } elseif ($fcount <= $THRESHOLD) {
            $status = 'สงสัย';
            $total_suspect++;
            $suspect_list[] = ['theme' => $name, 'site' => $site, 'files' => $fcount];
        } elseif (!has_theme_header($d)) {
            // ไฟล์เยอะแต่ไม่มี style.css ที่มี Theme Name = ไฟล์หลักหาย/เสียหาย
            $status = 'ไม่มีหลัก';
            $total_nohdr++;
            $nohdr_list[] = ['theme' => $name, 'site' => $site, 'files' => $fcount];
        } else {
            $status = 'ปกติ';
        }

        if ($AS_CSV) {
            echo "\"$site\",\"$name\",$fcount,$bytes,$status\n";
        } else {
            if ($ONLY_ISSUES && $status === 'ปกติ') continue;
            printf("  %-12s %-7s %-9s %s\n", $status, $fcount, $size, $name);
        }
    }
    if (!$AS_CSV) echo "\n";
}

// ====== สรุปท้าย (เฉพาะโหมดปกติ) ======
if (!$AS_CSV) {
    echo "=======================================================\n";
    echo " สรุปผล\n";
    echo "=======================================================\n";
    echo " จำนวนไซต์ที่สแกน      : " . count($theme_dirs) . "\n";
    echo " จำนวนธีมทั้งหมด       : $total_themes\n";
    echo " โฟลเดอร์ว่างเปล่า     : $total_empty\n";
    echo " ไฟล์น้อยผิดปกติ       : $total_suspect\n";
    echo " ไม่มีไฟล์หลัก         : $total_nohdr\n";
    echo " parent ไฟล์หลักขาด   : $total_core\n\n";

    $group_and_print = function($list, $heading) {
        if (count($list) === 0) return;
        $groups = [];
        foreach ($list as $item) {
            $groups[$item['theme']][] = $item;
        }
        uasort($groups, function($a, $b) { return count($b) - count($a); });

        echo "$heading\n";
        foreach ($groups as $theme => $items) {
            $n = count($items);
            echo "\n  ┌─ [$theme] กระทบ $n เว็บ\n";
            foreach ($items as $it) {
                $extra = ($it['files'] > 0) ? " ({$it['files']} ไฟล์)" : "";
                echo "  │   • {$it['site']}$extra\n";
            }
            echo "  └─────────────────────────────────────────\n";
        }
        echo "\n";
    };

    if ($total_core > 0) {
        $group_and_print($core_list, "** [PARENT] blocksy ขาดไฟล์หลัก (style.css/index.php/functions.php) — ธีมพัง **");
    }
    if ($total_empty > 0) {
        $group_and_print($empty_list, "** โฟลเดอร์ธีมที่ว่างเปล่า (ต้องติดตั้งใหม่) — จัดกลุ่มตามธีม **");
    }
    if ($total_nohdr > 0) {
        $group_and_print($nohdr_list, "** ธีมไม่มีไฟล์หลัก (ไฟล์เยอะแต่ style.css หาย — ใช้งานไม่ได้) — จัดกลุ่มตามธีม **");
    }
    if ($total_suspect > 0) {
        $group_and_print($suspect_list, "** ธีมไฟล์น้อยผิดปกติ (ควรตรวจเพิ่ม) — จัดกลุ่มตามธีม **");
    }
    if ($total_empty === 0 && $total_suspect === 0 && $total_nohdr === 0 && $total_core === 0) {
        echo " ไม่พบความผิดปกติ ธีมทุกตัวมีไฟล์ครบถ้วน\n\n";
    }
    echo " หมายเหตุ: สคริปต์นี้อ่านอย่างเดียว ไม่แก้ไขไฟล์ใด ๆ\n";
}
