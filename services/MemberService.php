<?php
/**
 * Member Service
 * Business logic for member operations
 */

require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/helpers.php';

class MemberService
{

    // Source Constants
    const SOURCE_MANUAL = 'manual';
    const SOURCE_IMPORT_STANDARD = 'import_standard';
    const SOURCE_IMPORT_GOOGLE = 'import_google_forms';



    /**
     * Get full member profile with all statistics
     * 
     * @param string $permanentCode Member's permanent code
     * @return array|null Profile data or null if not found
     */
    public static function getProfile($inputCode)
    {
        $pdo = db();

        $permanentCode = $inputCode;

        // RESOLVE TOKEN TO CODE
        // If input is long (UUID) or looks like a token, try to find it in registrations
        if (strlen($inputCode) > 15 || strpos($inputCode, '-') !== false) {
            $stmt = $pdo->prepare("
                SELECT m.permanent_code 
                FROM registrations r
                JOIN members m ON r.member_id = m.id
                WHERE r.session_badge_token = ?
             ");
            $stmt->execute([$inputCode]);
            $foundCode = $stmt->fetchColumn();
            if ($foundCode) {
                $permanentCode = $foundCode;
            }
        }

        // NEW: Check by Wasel (if input is numeric)
        if ($permanentCode === $inputCode && is_numeric($inputCode)) {
            try {
                $stmt = $pdo->prepare("SELECT m.permanent_code FROM registrations r JOIN members m ON r.member_id = m.id WHERE r.wasel = ? LIMIT 1");
                $stmt->execute([$inputCode]);
                $found = $stmt->fetchColumn();
                if ($found)
                    $permanentCode = $found;
            } catch (Exception $e) {
            }
        }

        // NEW: Check by Member ID directly (useful for archives where registrations are deleted)
        if ($permanentCode === $inputCode && is_numeric($inputCode)) {
            try {
                $stmt = $pdo->prepare("SELECT permanent_code FROM members WHERE id = ? LIMIT 1");
                $stmt->execute([$inputCode]);
                $found = $stmt->fetchColumn();
                if ($found)
                    $permanentCode = $found;
            } catch (Exception $e) {
            }
        }

        // NEW: Check by Badge ID (in participants)
        if ($permanentCode === $inputCode) {
            try {
                $stmt = $pdo->prepare("SELECT m.permanent_code FROM participants p JOIN members m ON p.registration_code = m.permanent_code WHERE p.badge_id = ? LIMIT 1");
                $stmt->execute([$inputCode]);
                $found = $stmt->fetchColumn();
                if ($found)
                    $permanentCode = $found;
            } catch (Exception $e) {
            }
        }

        // Get member
        $stmt = $pdo->prepare("SELECT * FROM members WHERE permanent_code = ? AND is_active = 1");
        $stmt->execute([$permanentCode]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            // FALLBACK TO JSON FILES (Mimic admin/members.php behavior)
            // 1. Check data.json (Approved Registrations)
            $dataFile = __DIR__ . '/../admin/data/data.json';
            if (file_exists($dataFile)) {
                $jsonData = json_decode(file_get_contents($dataFile), true) ?? [];
                foreach ($jsonData as $reg) {
                    $match = false;
                    // Check by Permanent Code
                    if (($reg['registration_code'] ?? '') === $permanentCode)
                        $match = true;
                    // Check by Badge Token (Input)
                    if (($reg['badge_token'] ?? '') === $inputCode)
                        $match = true;
                    // Check by Session Token
                    if (($reg['session_badge_token'] ?? '') === $inputCode)
                        $match = true;
                    // Check by Wasel
                    if (($reg['wasel'] ?? '') == $inputCode)
                        $match = true;

                    if ($match) {
                        // NO AUTO-MIGRATE TO DATABASE
                        // We simply construct a mock member object from the JSON data
                        // The actual migration mapping happens ONLY at reset_championship.php

                        // Try to enrich from members.json if available
                        $richData = $reg;
                        $mJsonFile = __DIR__ . '/../admin/data/members.json';
                        if (file_exists($mJsonFile)) {
                            $mJson = json_decode(file_get_contents($mJsonFile), true) ?? [];
                            $pCode = $reg['registration_code'] ?? $permanentCode;
                            if (isset($mJson[$pCode])) {
                                $richData = array_merge($reg, $mJson[$pCode]);
                                $richData['wasel'] = $reg['wasel'];
                            }
                        }

                        $phoneStr = (string) ($richData['phone'] ?? $reg['phone'] ?? '');
                        $cleanPhone = preg_replace('/[^0-9]/', '', $phoneStr);
                        if (strlen($cleanPhone) < 10)
                            $cleanPhone = '0000000000';

                        $nameStr = trim($richData['full_name'] ?? $richData['name'] ?? 'Unknown');
                        if (empty($nameStr))
                            $nameStr = 'Member ' . ($reg['wasel'] ?? uniqid());

                        $member = [
                            'id' => $reg['wasel'],
                            'name' => $nameStr,
                            'phone' => $cleanPhone,
                            'permanent_code' => $reg['registration_code'] ?? $permanentCode,
                            'governorate' => $richData['governorate'] ?? '',
                            'is_active' => 1,
                            'account_activated' => 1,
                            'source' => 'json_data_fallback'
                        ];

                        // Construct mocked registration for return array (legacy logic preserved)
                        if (!isset($currentRegistration)) {
                            $currentRegistration = [
                                'id' => $reg['wasel'],
                                'member_id' => $member['id'] ?? $reg['wasel'],
                                'championship_id' => $reg['championship_id'] ?? '2025_default',
                                'wasel' => $reg['wasel'],
                                'status' => $reg['status'] ?? 'approved',
                                'car_type' => $reg['car_type'] ?? $richData['car_type'] ?? '',
                                'car_year' => $reg['car_year'] ?? $richData['car_year'] ?? $richData['car_model'] ?? '',
                                'car_color' => $reg['car_color'] ?? $richData['car_color'] ?? '',
                                'engine_size' => $reg['engine_size'] ?? $richData['engine_size'] ?? '',
                                'engine_size_label' => $reg['engine_size_label'] ?? $richData['engine_size_label'] ?? '',
                                'plate_number' => $reg['plate_number'] ?? $richData['plate_number'] ?? '',
                                'plate_letter' => $reg['plate_letter'] ?? $richData['plate_letter'] ?? '',
                                'plate_governorate' => $reg['plate_governorate'] ?? $richData['plate_governorate'] ?? '',
                                'plate_full' => $reg['plate_full'] ?? (($reg['plate_governorate'] ?? '') . ' ' . ($reg['plate_letter'] ?? '') . ' ' . ($reg['plate_number'] ?? '')),
                                'participation_type' => $reg['participation_type'] ?? $richData['participation_type'] ?? '',
                                'participation_type_label' => $reg['participation_type_label'] ?? $richData['participation_type_label'] ?? '',
                                'full_name' => $nameStr,
                                'governorate' => $richData['governorate'] ?? $reg['governorate'] ?? '',
                                'personal_photo' => $reg['personal_photo'] ?? $richData['personal_photo'] ?? '',
                                'images' => [
                                    'personal_photo' => $reg['personal_photo'] ?? $richData['personal_photo'] ?? '',
                                    'front_image' => $reg['front_image'] ?? $richData['front_image'] ?? $richData['images']['front_image'] ?? '',
                                    'side_image' => $reg['side_image'] ?? $richData['side_image'] ?? $richData['images']['side_image'] ?? '',
                                    'back_image' => $reg['back_image'] ?? $richData['back_image'] ?? $richData['images']['back_image'] ?? '',
                                    'edited_image' => $reg['edited_image'] ?? $richData['edited_image'] ?? $richData['images']['edited_image'] ?? '',
                                    'acceptance_image' => $reg['acceptance_image'] ?? $richData['acceptance_image'] ?? $richData['images']['acceptance_image'] ?? '',
                                    'id_front' => $reg['images']['national_id_front'] ?? $richData['images']['national_id_front'] ?? $reg['images']['id_front'] ?? $richData['images']['id_front'] ?? $richData['national_id_front'] ?? $reg['national_id_front'] ?? $richData['id_front'] ?? $reg['id_front'] ?? '',
                                    'id_back' => $reg['images']['national_id_back'] ?? $richData['images']['national_id_back'] ?? $reg['images']['id_back'] ?? $richData['images']['id_back'] ?? $richData['national_id_back'] ?? $reg['national_id_back'] ?? $richData['id_back'] ?? $reg['id_back'] ?? '',
                                    'license_front' => $reg['images']['license_front'] ?? $richData['images']['license_front'] ?? $reg['license_front'] ?? $richData['license_front'] ?? '',
                                    'license_back' => $reg['images']['license_back'] ?? $richData['images']['license_back'] ?? $reg['license_back'] ?? $richData['license_back'] ?? ''
                                ],
                                'saved_frame_settings' => $reg['saved_frame_settings'] ?? null,
                                'is_active' => 1,
                                'assigned_time' => $reg['assigned_time'] ?? $richData['assigned_time'] ?? null,
                                'assigned_date' => $reg['assigned_date'] ?? $richData['assigned_date'] ?? null,
                                'assigned_order' => $reg['assigned_order'] ?? $richData['assigned_order'] ?? null
                            ];
                        }
                        break;
                    }
                }
            }

            // 2. Check members.json (Legacy) if still not found
            if (!$member) {
                $membersFile = __DIR__ . '/../admin/data/members.json';
                if (file_exists($membersFile)) {
                    $mJson = json_decode(file_get_contents($membersFile), true) ?? [];
                    if (isset($mJson[$permanentCode])) {
                        $reg = $mJson[$permanentCode];
                        $member = [
                            'id' => $reg['id'] ?? uniqid(),
                            'name' => $reg['name'] ?? 'Unknown',
                            'phone' => $reg['phone'] ?? '',
                            'permanent_code' => $permanentCode,
                            'governorate' => $reg['governorate'] ?? '',
                            'is_active' => 1,
                            'source' => 'json_legacy'
                        ];

                        // Also reconstruct current_registration for legacy fallback
                        $currentRegistration = [
                            'id' => $reg['id'] ?? uniqid(),
                            'wasel' => $reg['id'] ?? 0,
                            'status' => 'approved',
                            'car_type' => $reg['car_type'] ?? '',
                            'car_year' => $reg['car_year'] ?? '',
                            'car_color' => $reg['car_color'] ?? '',
                            'plate_full' => ($reg['plate_governorate'] ?? '') . ' ' . ($reg['plate_letter'] ?? '') . ' ' . ($reg['plate_number'] ?? ''),
                            'full_name' => $member['name'],
                            'governorate' => $member['governorate'],
                            'personal_photo' => $reg['personal_photo'] ?? $reg['images']['personal_photo'] ?? '',
                            'images' => $reg['images'] ?? [
                                'personal_photo' => $reg['personal_photo'] ?? '',
                                'id_front' => $reg['id_front'] ?? $reg['national_id_front'] ?? '',
                                'id_back' => $reg['id_back'] ?? $reg['national_id_back'] ?? '',
                                'license_front' => $reg['license_front'] ?? $reg['images']['license_front'] ?? '',
                                'license_back' => $reg['license_back'] ?? $reg['images']['license_back'] ?? ''
                            ],
                            'participation_type_label' => $reg['participation_type'] ?? '',
                            'is_active' => 1
                        ];
                    }
                }
            }

            // If still null, then truly not found
            if (!$member) {
                return null;
            }

            // Fetch Warnings and Notes for legacy JSON member
            $warnings = [];
            $notes = [];
            $phoneNoteCondition = "";
            $phoneParams = [$member['id']];
            if (!empty($member['phone']) && strlen($member['phone']) > 5) {
                $phoneNoteCondition = "OR member_id IN (SELECT id FROM members WHERE phone = ?)";
                $phoneParams[] = normalizePhone($member['phone']);
            }

            try {
                // Get warnings
                $stmt = $pdo->prepare("
                    SELECT w.*, c.name as championship_name, u.username as created_by_name, u.username as created_by_username
                    FROM warnings w
                    LEFT JOIN championships c ON w.championship_id = c.id
                    LEFT JOIN users u ON w.created_by = u.id
                    WHERE (w.member_id = ? " . str_replace("member_id", "w.member_id", $phoneNoteCondition) . ") AND w.is_resolved = 0
                    ORDER BY w.severity DESC, w.created_at DESC
                ");
                $stmt->execute($phoneParams);
                $warnings = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Get notes
                $stmt = $pdo->prepare("
                    SELECT n.*, p.wasel, p.badge_id, p.registration_code, u.username as created_by_name, u.username as created_by_username
                    FROM notes n
                    LEFT JOIN participants p ON n.participant_id = p.id
                    LEFT JOIN users u ON n.created_by = u.id
                    WHERE (n.member_id = ? " . str_replace("member_id", "n.member_id", $phoneNoteCondition) . ") 
                       OR p.registration_code = ? 
                    ORDER BY n.created_at DESC
                    LIMIT 50
                ");
                $noteParams = array_merge($phoneParams, [$permanentCode]);
                $stmt->execute($noteParams);
                $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Merge notes into warnings
                foreach ($notes as $note) {
                    if (in_array($note['note_type'], ['warning', 'blocker'])) {
                        $mappedSeverity = ($note['note_type'] === 'blocker') ? 'high' : 'medium';
                        $creatorName = $note['created_by_name'] ?: $note['created_by_username'] ?: 'نظام';
                        $warnings[] = [
                            'id' => 'note_' . $note['id'],
                            'warning_text' => '[ملاحظة] ' . $note['note_text'],
                            'severity' => $mappedSeverity,
                            'created_at' => $note['created_at'],
                            'expires_at' => null,
                            'championship_name' => 'عام',
                            'is_resolved' => 0,
                            'source' => 'note',
                            'created_by_name' => $creatorName
                        ];
                    }
                }

                // Sort
                usort($warnings, function ($a, $b) {
                    return strtotime($b['created_at']) - strtotime($a['created_at']);
                });
            } catch (Exception $e) {
            }

            // For JSON members, calculate real stats from round_logs.json
            $jsonRoundsCount = 0;
            $roundLogsFile = __DIR__ . '/../admin/data/round_logs.json';
            if (file_exists($roundLogsFile)) {
                $rLogs = json_decode(file_get_contents($roundLogsFile), true) ?? [];
                $wasel = $member['id'] ?? '';
                $pCode = $permanentCode;
                $enteredRounds = [];
                foreach ($rLogs as $rl) {
                    if ($rl['action'] === 'enter') {
                        $pid = $rl['participant_id'] ?? '';
                        if ($pid == $wasel || $pid === $pCode) {
                            $rid = $rl['round_id'] ?? 0;
                            $enteredRounds[$rid] = true;
                        }
                    }
                }
                $jsonRoundsCount = count($enteredRounds);
            }

            // Count approved registrations from data.json for championships
            $jsonChampsCount = !empty($currentRegistration) ? 1 : 0;

            return [
                'member' => $member,
                'championships_count' => $jsonChampsCount,
                'rounds_entered' => $jsonRoundsCount,
                'warnings' => $warnings,
                'warnings_count' => count($warnings),
                'notes' => $notes,
                'registrations' => [$currentRegistration ?? []],
                'current_registration' => $currentRegistration ?? null,
                'is_registered_current' => !empty($currentRegistration),
                'is_approved_current' => true,
                'has_blockers' => false
            ];
        }

        // Get championships count (Unified Formula: Permanent + Current Approved + Manual Overrides)
        $stmt = $pdo->prepare("
            SELECT (
                COALESCE(m.championships_participated, 0) + 
                COALESCE(m.manual_championships_count, 0) + 
                (SELECT COUNT(*) FROM registrations r WHERE r.member_id = m.id AND r.status = 'approved' AND r.is_active = 1)
            ) as total_count
            FROM members m WHERE m.id = ?
        ");
        $stmt->execute([$member['id']]);
        $championshipsCount = (int) $stmt->fetchColumn();

        // Get all registrations history
        $stmt = $pdo->prepare("
                SELECT r.*, 
                       m.name as full_name,
                       m.governorate,
                       m.national_id_front,
                       m.national_id_back,
                       COALESCE(c.name, 'بطولة') as championship_name
                FROM registrations r
                JOIN members m ON r.member_id = m.id
                JOIN championships c ON r.championship_id = c.id
                WHERE r.member_id = ? AND r.is_active = 1
                ORDER BY r.created_at DESC
            ");
        $stmt->execute([$member['id']]);
        $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build list of identifiers (Permanent Code + All Session Tokens) FIRST
        $identifiers = [$permanentCode];
        foreach ($registrations as $r) {
            if (!empty($r['session_badge_token'])) {
                $identifiers[] = $r['session_badge_token'];
            }
        }

        // MERGE HISTORICAL REGISTRATIONS FROM ARCHIVES (Real History)
        $archivesDir = __DIR__ . '/../admin/data/archives';
        if (is_dir($archivesDir)) {
            $files = glob($archivesDir . '/*.json');
            rsort($files); // Newest first
            $files = array_slice($files, 0, 10); // Check last 10 archives only for performance

            foreach ($files as $file) {
                $archivedData = json_decode(file_get_contents($file), true) ?? [];
                $registrants = $archivedData['data'] ?? [];
                $champDate = $archivedData['date'] ?? null;

                // Extract date from filename if needed: championship_2026-01-31_02-29-57.json
                if (!$champDate && preg_match('/championship_(\d{4}-\d{2}-\d{2})/', basename($file), $m)) {
                    $champDate = $m[1];
                }

                foreach ($registrants as $reg) {
                    // Match by Permanent Code OR Wasel ID
                    // Note: Old archives might not have permanent_code, so use wasel if codes match
                    $isMatch = false;

                    if (!empty($reg['registration_code']) && $reg['registration_code'] === $permanentCode)
                        $isMatch = true;
                    if (!$isMatch && !empty($reg['phone']) && $reg['phone'] === $member['phone'])
                        $isMatch = true;

                    if ($isMatch) {
                        // Avoid duplicates if already in DB (check by championship_id if possible)
                        // Archives usually have 'championship_id', but it might be '2026_default' repeatedly.
                        // Better to check approximate date or just add it if not in $registrations keys

                        // Create a unique key for this registration to prevent dups
                        $regKey = 'arch_' . basename($file) . '_' . ($reg['wasel'] ?? uniqid());

                        $archiveImages = $reg['images'] ?? [];
                        $registrations[] = [
                            'id' => $regKey,
                            'championship_id' => 0, // 0 indicates archive/external
                            'championship_name' => 'أرشيف: ' . ($champDate ? date('Y-m-d', strtotime($champDate)) : 'سابق'),
                            'member_id' => $member['id'],
                            'car_type' => $reg['car_type'] ?? '',
                            'car_model' => $reg['car_year'] ?? '',
                            'car_year' => $reg['car_year'] ?? '',
                            'car_color' => $reg['car_color'] ?? '',
                            'plate_number' => $reg['plate_number'] ?? '',
                            'plate_governorate' => $reg['plate_governorate'] ?? '',
                            'plate_letter' => $reg['plate_letter'] ?? '',
                            'participation_type' => $reg['participation_type'] ?? '',
                            'participation_type_label' => $reg['participation_type_label'] ?? '',
                            'status' => 'approved',
                            'created_at' => $reg['registration_date'] ?? ($champDate ?: date('Y-m-d H:i:s')),
                            'is_active' => 0,
                            'personal_photo' => $reg['personal_photo'] ?? $archiveImages['personal_photo'] ?? '',
                            'images' => [
                                'personal_photo' => $reg['personal_photo'] ?? $archiveImages['personal_photo'] ?? '',
                                'front_image' => $reg['front_image'] ?? $archiveImages['front_image'] ?? '',
                                'side_image' => $reg['side_image'] ?? $archiveImages['side_image'] ?? '',
                                'back_image' => $reg['back_image'] ?? $archiveImages['back_image'] ?? '',
                                'edited_image' => $reg['edited_image'] ?? $archiveImages['edited_image'] ?? '',
                                'acceptance_image' => $reg['acceptance_image'] ?? $archiveImages['acceptance_image'] ?? '',
                                'id_front' => $reg['national_id_front'] ?? $archiveImages['national_id_front'] ?? $reg['id_front'] ?? '',
                                'id_back' => $reg['national_id_back'] ?? $archiveImages['national_id_back'] ?? $reg['id_back'] ?? ''
                            ],
                            'engine_size' => $reg['engine_size'] ?? '',
                            'engine_size_label' => $reg['engine_size_label'] ?? '',
                            'wasel' => $reg['wasel'] ?? '',
                            'source' => 'archive'
                        ];
                    }
                }
            }
        }

        // Sorting registrations by date DESC
        usort($registrations, function ($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        // Recalculate Championships Count (DB + Archives)
        // Count unique dates/files from archives + DB championships
        $uniqueChamps = [];
        foreach ($registrations as $r) {
            if ($r['championship_id'] > 0) {
                $uniqueChamps['db_' . $r['championship_id']] = true;
            } elseif (isset($r['source']) && $r['source'] === 'archive') {
                $uniqueChamps[$r['championship_name']] = true; // Use name/date as unique key
            }
        }

        // Count historical round appearances correctly from JSON as fallback
        $roundsEntered = 0; // Initialize variable to avoid warning

        $membersFile = __DIR__ . '/../admin/data/members.json';
        if (file_exists($membersFile)) {
            $mJson = json_decode(file_get_contents($membersFile), true) ?? [];
            if (isset($mJson[$permanentCode])) {
                $histData = $mJson[$permanentCode];
                // Add historical rounds (accumulate)
                if (!empty($histData['total_rounds_all_time'])) {
                    $roundsEntered = max($roundsEntered, intval($histData['total_rounds_all_time']));
                }
            }
        }

        // Get rounds entered (from round_logs via participants + Manual Overrides)
        // FIXED: Count by Code OR Token

        // Auto-add manual_rounds_count if it doesn't exist
        try {
            $pdo->exec("ALTER TABLE members ADD COLUMN manual_rounds_count INTEGER DEFAULT 0");
        } catch (\Exception $e) {
            // Column already exists or other non-fatal error
        }

        $roundsPlaceholder = implode(',', array_fill(0, count($identifiers), '?'));
        $stmt = $pdo->prepare("
            SELECT (
                (SELECT COUNT(*) FROM round_logs rl
                 JOIN participants p ON rl.participant_id = p.id
                 WHERE (p.registration_code = ? OR p.badge_id IN ($roundsPlaceholder))
                 AND rl.action = 'enter') +
                COALESCE((SELECT manual_rounds_count FROM members WHERE id = ?), 0)
            ) as total_rounds
        ");
        $roundsParams = array_merge([$permanentCode], $identifiers, [$member['id']]);
        $stmt->execute($roundsParams);
        $roundsEntered = (int) $stmt->fetchColumn();

        // MERGE HISTORICAL DATA FROM JSON (Legacy)
        // Add counts from members.json if available
        $membersFile = __DIR__ . '/../admin/data/members.json';
        if (file_exists($membersFile)) {
            $mJson = json_decode(file_get_contents($membersFile), true) ?? [];
            if (isset($mJson[$permanentCode])) {
                $histData = $mJson[$permanentCode];
                // Add historical rounds
                if (!empty($histData['total_rounds_all_time'])) {
                    $roundsEntered += intval($histData['total_rounds_all_time']);
                }
            }
        }

        // Get active warnings (UNIFIED BY PHONE)
        // If user has multiple accounts with same phone, show warnings from all of them
        $memberPhone = $member['phone'];
        $phoneCondition = "";
        $phoneParams = [$member['id']];

        if (!empty($memberPhone) && strlen($memberPhone) > 5) {
            $phoneCondition = "OR w.member_id IN (SELECT id FROM members WHERE phone = ?)";
            $phoneParams[] = $memberPhone;
        }

        $stmt = $pdo->prepare("
            SELECT w.*, c.name as championship_name, u.username as created_by_name, u.username as created_by_username
            FROM warnings w
            LEFT JOIN championships c ON w.championship_id = c.id
            LEFT JOIN users u ON w.created_by = u.id
            WHERE (w.member_id = ? $phoneCondition) AND w.is_resolved = 0
            ORDER BY w.severity DESC, w.created_at DESC
        ");
        $stmt->execute($phoneParams);
        $warnings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // MERGE JSON WARNINGS (From admin/members.php)
        // This unifies manual warnings with DB warnings
        $membersJsonFile = __DIR__ . '/../admin/data/members.json';
        if (file_exists($membersJsonFile)) {
            $membersData = json_decode(file_get_contents($membersJsonFile), true) ?? [];
            // Look for member by permanent_code
            if (isset($membersData[$permanentCode])) {
                $jsonWarnings = $membersData[$permanentCode]['warnings'] ?? [];
                foreach ($jsonWarnings as $jw) {
                    // Check expiry
                    if (!empty($jw['expires_at']) && strtotime($jw['expires_at']) < time()) {
                        continue;
                    }

                    // Add to main warnings list
                    // Map JSON structure to DB structure
                    $warnings[] = [
                        'id' => $jw['id'] ?? uniqid(),
                        'warning_text' => $jw['text'] ?? '',
                        'severity' => $jw['severity'] ?? 'medium',
                        'created_at' => $jw['created_at'] ?? '',
                        'expires_at' => $jw['expires_at'] ?? null,
                        'championship_name' => 'تنبيه إداري', // Label for manual warnings
                        'is_resolved' => 0,
                        'created_by_name' => 'Admin (Manual)'
                    ];
                }
            }
        }

        // Get notes (from participants linked to this member OR directly via member_id OR BY PHONE)
        $placeholders = implode(',', array_fill(0, count($identifiers), '?'));

        // Build Params
        $params = array_merge([$member['id'], $permanentCode], $identifiers);

        $phoneNoteCondition = "";
        if (!empty($memberPhone) && strlen($memberPhone) > 5) {
            $phoneNoteCondition = "OR n.member_id IN (SELECT id FROM members WHERE phone = ?)";
            $params[] = $memberPhone;
        }

        // Use LEFT JOIN to include notes that only have member_id (no participant_id)
        $stmt = $pdo->prepare("
            SELECT n.*, p.wasel, p.badge_id, p.registration_code, u.username as created_by_name, u.username as created_by_username
            FROM notes n
            LEFT JOIN participants p ON n.participant_id = p.id
            LEFT JOIN users u ON n.created_by = u.id
            WHERE n.member_id = ? 
               OR p.registration_code = ? 
               OR p.badge_id IN ($placeholders)
               $phoneNoteCondition
            ORDER BY n.created_at DESC
            LIMIT 50
        ");
        $stmt->execute($params);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // MERGE NOTES INTO WARNINGS (Unify Violations)
        // Treat notes of type 'warning' and 'blocker' as warnings
        foreach ($notes as $note) {
            if (in_array($note['note_type'], ['warning', 'blocker'])) {
                // Check if this note is already in warnings (unlikely but safe check)
                // We construct a warning-like object
                $mappedSeverity = ($note['note_type'] === 'blocker') ? 'high' : 'medium';
                $creatorName = $note['created_by_name'] ?: $note['created_by_username'] ?: 'نظام';

                $warnings[] = [
                    'id' => 'note_' . $note['id'],
                    'warning_text' => '[ملاحظة] ' . $note['note_text'],
                    'severity' => $mappedSeverity,
                    'created_at' => $note['created_at'],
                    'expires_at' => null,
                    'championship_name' => $member['name'] ? 'عام' : 'عام', // Simplified
                    'is_resolved' => 0,
                    'source' => 'note', // Marker
                    'created_by_name' => $creatorName
                ];
            }
        }

        // Sort warnings by date desc
        usort($warnings, function ($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });

        // Current championship registration
        $currentChampId = getCurrentChampionshipId();
        $stmt = $pdo->prepare("
                SELECT r.*, 
                       m.name as full_name,
                       m.governorate,
                       COALESCE(c.name, 'بطولة') as championship_name
                FROM registrations r
                JOIN members m ON r.member_id = m.id
                JOIN championships c ON r.championship_id = c.id
                WHERE r.member_id = ? AND r.championship_id = ? AND r.is_active = 1
            ");
        $stmt->execute([$member['id'], $currentChampId]);
        $currentRegistration = $stmt->fetch(PDO::FETCH_ASSOC);

        // --- DATA BACKFILL & FALLBACK LOGIC ---
        $dataFile = __DIR__ . '/../admin/data/data.json';
        $membersFile = __DIR__ . '/../admin/data/members.json';
        $foundData = null;

        if (file_exists($membersFile)) {
            $mJson = json_decode(file_get_contents($membersFile), true) ?? [];
            if (isset($mJson[$permanentCode])) {
                $foundData = $mJson[$permanentCode];
            }
            // Secondary match by phone if code fails
            if (!$foundData && !empty($member['phone'])) {
                $targetPhone = normalizePhone($member['phone']);
                foreach ($mJson as $item) {
                    if (normalizePhone($item['phone'] ?? '') === $targetPhone) {
                        $foundData = $item;
                        break;
                    }
                }
            }
        }

        // ALWAYS check data.json for images even if foundData already set from members.json
        // Because members.json might have empty images while data.json has the actual uploaded paths
        if (file_exists($dataFile)) {
            $dJson = json_decode(file_get_contents($dataFile), true) ?? [];
            $dataJsonEntry = null;
            foreach ($dJson as $item) {
                if (($item['registration_code'] ?? '') === $permanentCode || ($item['badge_token'] ?? '') === $permanentCode) {
                    $dataJsonEntry = $item;
                    break;
                }
            }
            // Secondary match by phone if code fails
            if (!$dataJsonEntry && !empty($member['phone'])) {
                $targetPhone = normalizePhone($member['phone']);
                foreach ($dJson as $item) {
                    if (normalizePhone($item['phone'] ?? '') === $targetPhone) {
                        $dataJsonEntry = $item;
                        break;
                    }
                }
            }

            if ($dataJsonEntry) {
                if (!$foundData) {
                    $foundData = $dataJsonEntry;
                } else {
                    // Merge images from data.json into foundData if foundData images are empty
                    $djImages = $dataJsonEntry['images'] ?? [];
                    $fdImages = $foundData['images'] ?? [];

                    // Merge nested images array
                    foreach ($djImages as $k => $v) {
                        if (!empty($v) && empty($fdImages[$k])) {
                            $fdImages[$k] = $v;
                        }
                    }
                    $foundData['images'] = $fdImages;

                    // Also merge top-level image fields
                    $imgFields = ['personal_photo', 'front_image', 'side_image', 'back_image', 'edited_image', 'acceptance_image', 'national_id_front', 'national_id_back', 'id_front', 'id_back'];
                    foreach ($imgFields as $f) {
                        if (!empty($dataJsonEntry[$f]) && empty($foundData[$f])) {
                            $foundData[$f] = $dataJsonEntry[$f];
                        }
                    }
                }
            }
        }

        // Determine merged source values
        // Extract latest known values from history
        $latestReg = $registrations[0] ?? null;

        $uType = $foundData['car_type'] ?? $latestReg['car_type'] ?? $member['last_car_type'] ?? '';
        $uYear = $foundData['car_year'] ?? $foundData['car_model'] ?? $latestReg['car_year'] ?? $member['last_car_year'] ?? '';
        $uColor = $foundData['car_color'] ?? $latestReg['car_color'] ?? $member['last_car_color'] ?? '';
        $uPart = $foundData['participation_type'] ?? $latestReg['participation_type'] ?? $member['last_participation_type'] ?? '';
        $uEngine = $foundData['engine_size'] ?? $foundData['engine'] ?? $latestReg['engine_size'] ?? $member['last_engine_size'] ?? '';
        $uPlateGov = $foundData['plate_governorate'] ?? $latestReg['plate_governorate'] ?? $member['last_plate_governorate'] ?? '';
        $uPlateLet = $foundData['plate_letter'] ?? $latestReg['plate_letter'] ?? $member['last_plate_letter'] ?? '';
        $uPlateNum = $foundData['plate_number'] ?? $latestReg['plate_number'] ?? $member['last_plate_number'] ?? '';

        // --- IMPROVEMENT: Search complete history for the first available image ---
        // Instead of just relying on $latestReg (which might be pending and empty), we search the entire history.
        $uPhoto = $foundData['personal_photo'] ?? ($foundData['images']['personal_photo'] ?? '') ?: ($member['personal_photo'] ?? '');
        $uFront = $foundData['front_image'] ?? ($foundData['images']['front_image'] ?? '');
        $uSide = $foundData['side_image'] ?? ($foundData['images']['side_image'] ?? '');
        $uBack = $foundData['back_image'] ?? ($foundData['images']['back_image'] ?? '');
        $uEdited = $foundData['edited_image'] ?? ($foundData['images']['edited_image'] ?? '');
        $uAccept = $foundData['acceptance_image'] ?? ($foundData['images']['acceptance_image'] ?? '');
        $uIdFront = ($foundData['national_id_front'] ?? '') ?: ($foundData['images']['national_id_front'] ?? '') ?: ($foundData['id_front'] ?? '') ?: ($foundData['images']['id_front'] ?? '') ?: ($member['national_id_front'] ?? '');
        $uIdBack = ($foundData['national_id_back'] ?? '') ?: ($foundData['images']['national_id_back'] ?? '') ?: ($foundData['id_back'] ?? '') ?: ($foundData['images']['id_back'] ?? '') ?: ($member['national_id_back'] ?? '');
        $uLicenseFront = ($foundData['license_front'] ?? '') ?: ($foundData['images']['license_front'] ?? '');
        $uLicenseBack = ($foundData['license_back'] ?? '') ?: ($foundData['images']['license_back'] ?? '');

        // Fallback to registrations history
        foreach ($registrations as $r) {
            if (empty($uPhoto))
                $uPhoto = ($r['personal_photo'] ?? '') ?: ($r['images']['personal_photo'] ?? '');
            if (empty($uFront))
                $uFront = ($r['front_image'] ?? '') ?: ($r['images']['front_image'] ?? '');
            if (empty($uSide))
                $uSide = ($r['side_image'] ?? '') ?: ($r['images']['side_image'] ?? '');
            if (empty($uBack))
                $uBack = ($r['back_image'] ?? '') ?: ($r['images']['back_image'] ?? '');
            if (empty($uEdited))
                $uEdited = ($r['edited_image'] ?? '') ?: ($r['images']['edited_image'] ?? '');
            if (empty($uAccept))
                $uAccept = ($r['acceptance_image'] ?? '') ?: ($r['images']['acceptance_image'] ?? '');
            if (empty($uIdFront))
                $uIdFront = ($r['national_id_front'] ?? '') ?: ($r['images']['national_id_front'] ?? '') ?: ($r['id_front'] ?? '') ?: ($r['images']['id_front'] ?? '');
            if (empty($uIdBack))
                $uIdBack = ($r['national_id_back'] ?? '') ?: ($r['images']['national_id_back'] ?? '') ?: ($r['id_back'] ?? '') ?: ($r['images']['id_back'] ?? '');
            if (empty($uLicenseFront))
                $uLicenseFront = ($r['license_front'] ?? '') ?: ($r['images']['license_front'] ?? '');
            if (empty($uLicenseBack))
                $uLicenseBack = ($r['license_back'] ?? '') ?: ($r['images']['license_back'] ?? '');
        }

        // Removed dangerous Dynamic Directory Scan - Only trust DB/JSON mapped paths

        // AUTO-FIX: Backfill missing data from JSON/Persistent if DB record is incomplete
        // 3. Apply to Current Registration OR Create Virtual One
        if ($currentRegistration) {
            // BACKFILL MISSING FIELDS IN DB (Silent Sync)
            $needsDbUpdate = false;
            $mapping = [
                'car_type' => $uType,
                'car_year' => $uYear,
                'car_color' => $uColor,
                'participation_type' => $uPart,
                'plate_governorate' => $uPlateGov,
                'plate_letter' => $uPlateLet,
                'plate_number' => $uPlateNum,
                'engine_size' => $uEngine,
                'personal_photo' => $uPhoto,
                'front_image' => $uFront,
                'side_image' => $uSide,
                'back_image' => $uBack,
                'edited_image' => $uEdited,
                'acceptance_image' => $uAccept,
                'saved_frame_settings' => $foundData['saved_frame_settings'] ?? null,
                'badge_token' => $foundData['badge_token'] ?? $currentRegistration['session_badge_token'] ?? null,
                'assigned_time' => $foundData['assigned_time'] ?? null,
                'assigned_date' => $foundData['assigned_date'] ?? null,
                'assigned_order' => $foundData['assigned_order'] ?? null
            ];

            foreach ($mapping as $key => $val) {
                if (empty($currentRegistration[$key]) && !empty($val)) {
                    $currentRegistration[$key] = $val;
                    $needsDbUpdate = true;
                }
            }

            if ($needsDbUpdate) {
                try {
                    $pdo->prepare("
                        UPDATE registrations SET 
                        car_type = ?, car_year = ?, car_color = ?, participation_type = ?,
                        plate_governorate = ?, plate_letter = ?, plate_number = ?, engine_size = ?,
                        personal_photo = ?, front_image = ?, side_image = ?, back_image = ?, edited_image = ?, acceptance_image = ?
                        WHERE id = ?
                    ")->execute([
                                $currentRegistration['car_type'],
                                $currentRegistration['car_year'],
                                $currentRegistration['car_color'],
                                $currentRegistration['participation_type'],
                                $currentRegistration['plate_governorate'],
                                $currentRegistration['plate_letter'],
                                $currentRegistration['plate_number'],
                                $currentRegistration['engine_size'],
                                $currentRegistration['personal_photo'],
                                $currentRegistration['front_image'],
                                $currentRegistration['side_image'],
                                $currentRegistration['back_image'],
                                $currentRegistration['edited_image'],
                                $currentRegistration['acceptance_image'],
                                $currentRegistration['id']
                            ]);
                } catch (Exception $e) {
                }
            }

            // AUTO-FIX: Backfill members table with recovered ID images
            $needsMemberUpdate = false;
            $mFront = $member['national_id_front'] ?? '';
            $mBack = $member['national_id_back'] ?? '';
            if (empty($mFront) && !empty($uIdFront)) {
                $mFront = $uIdFront;
                $needsMemberUpdate = true;
            }
            if (empty($mBack) && !empty($uIdBack)) {
                $mBack = $uIdBack;
                $needsMemberUpdate = true;
            }
            if ($needsMemberUpdate) {
                try {
                    $pdo->prepare("UPDATE members SET national_id_front = ?, national_id_back = ? WHERE id = ?")
                        ->execute([$mFront, $mBack, $member['id']]);
                } catch (Exception $e) {
                }
            }

            // UI COMPATIBILITY: Nested images array
            $currentRegistration['images'] = [
                'personal_photo' => ($currentRegistration['personal_photo'] ?? '') ?: $uPhoto,
                'front_image' => ($currentRegistration['front_image'] ?? '') ?: $uFront,
                'side_image' => ($currentRegistration['side_image'] ?? '') ?: $uSide,
                'back_image' => ($currentRegistration['back_image'] ?? '') ?: $uBack,
                'edited_image' => ($currentRegistration['edited_image'] ?? '') ?: $uEdited,
                'acceptance_image' => ($currentRegistration['acceptance_image'] ?? '') ?: $uAccept,
                'national_id_front' => ($member['national_id_front'] ?? '') ?: $uIdFront,
                'national_id_back' => ($member['national_id_back'] ?? '') ?: $uIdBack,
                'id_front' => $uIdFront,
                'id_back' => $uIdBack,
                'license_front' => $uLicenseFront,
                'license_back' => $uLicenseBack
            ];

            // Sync Plate Full
            $currentRegistration['plate_full'] = trim(($currentRegistration['plate_governorate'] ?? '') . ' ' . ($currentRegistration['plate_letter'] ?? '') . ' ' . ($currentRegistration['plate_number'] ?? ''));
            if (empty($currentRegistration['plate_full']) || $currentRegistration['plate_full'] === '')
                $currentRegistration['plate_full'] = '-';

            // COMPATIBILITY: Alias session_badge_token to badge_token
            if (!empty($currentRegistration['session_badge_token']) && empty($currentRegistration['badge_token'])) {
                $currentRegistration['badge_token'] = $currentRegistration['session_badge_token'];
            }
            if (empty($currentRegistration['full_name'])) {
                $currentRegistration['full_name'] = $member['name'];
            }
            if (empty($currentRegistration['governorate'])) {
                $currentRegistration['governorate'] = $member['governorate'];
            }
        } else {
            $currentRegistration = [
                'status' => 'approved',
                'car_type' => $uType,
                'car_year' => $uYear,
                'car_color' => $uColor,
                'participation_type' => $uPart,
                'engine_size' => $uEngine,
                'plate_governorate' => $uPlateGov,
                'plate_letter' => $uPlateLet,
                'plate_number' => $uPlateNum,
                'plate_full' => trim($uPlateGov . ' ' . $uPlateLet . ' ' . $uPlateNum),
                'full_name' => $member['name'],
                'governorate' => $member['governorate'],
                'personal_photo' => $uPhoto,
                'images' => [
                    'personal_photo' => $uPhoto,
                    'front_image' => $uFront,
                    'side_image' => $uSide,
                    'back_image' => $uBack,
                    'edited_image' => $uEdited,
                    'acceptance_image' => $uAccept,
                    'national_id_front' => $uIdFront,
                    'national_id_back' => $uIdBack,
                    'id_front' => $uIdFront,
                    'id_back' => $uIdBack,
                    'license_front' => $foundData['license_front'] ?? ($foundData['images']['license_front'] ?? ''),
                    'license_back' => $foundData['license_back'] ?? ($foundData['images']['license_back'] ?? '')
                ],
                'wasel' => $member['id'] ?? 0,
                'is_virtual' => true,
                'assigned_time' => $foundData['assigned_time'] ?? null,
                'assigned_date' => $foundData['assigned_date'] ?? null,
                'assigned_order' => $foundData['assigned_order'] ?? null
            ];
            if (empty($currentRegistration['plate_full']) || $currentRegistration['plate_full'] === '')
                $currentRegistration['plate_full'] = '-';
        }

        // 4. Ensure Member Photo is synced to main record if missing
        if ($uPhoto && empty($member['personal_photo'])) {
            try {
                $pdo->prepare("UPDATE members SET personal_photo = ? WHERE id = ?")->execute([$uPhoto, $member['id']]);
                $member['personal_photo'] = $uPhoto;
            } catch (Exception $e) {
            }
        }

        // 5. Finalize Engine Label
        if (!empty($currentRegistration['engine_size']) && empty($currentRegistration['engine_size_label'])) {
            $currentRegistration['engine_size_label'] = $currentRegistration['engine_size'];
        }

        return [
            'member' => $member,
            'championships_count' => $championshipsCount,
            'rounds_entered' => $roundsEntered,
            'warnings' => $warnings,
            'warnings_count' => count($warnings),
            'notes' => $notes,
            'registrations' => $registrations,
            'current_registration' => $currentRegistration,
            'is_registered_current' => !empty($currentRegistration) && !($currentRegistration['is_virtual'] ?? false),
            'is_approved_current' => ($currentRegistration['status'] ?? '') === 'approved',
            'has_blockers' => self::hasBlockerNotes($permanentCode)
        ];
    }

    /**
     * Update manual statistics for a member (Championships)
     */
    public static function updateManualStats($memberId, $championshipsCount)
    {
        $pdo = db();

        $desiredTotal = $championshipsCount === '' ? 0 : (int) $championshipsCount;

        // Calculate current derived total *without* the manual override
        $stmt = $pdo->prepare("
            SELECT (
                COALESCE(m.championships_participated, 0) + 
                (SELECT COUNT(*) FROM registrations r WHERE r.member_id = m.id AND r.status = 'approved' AND r.is_active = 1)
            ) as derived_count
            FROM members m WHERE m.id = ?
        ");
        $stmt->execute([$memberId]);
        $derivedCount = (int) $stmt->fetchColumn();

        // Calculate what the manual offset should be
        $newManualOffset = $desiredTotal - $derivedCount;
        if ($newManualOffset < 0)
            $newManualOffset = 0; // Prevent negative display if they type a number lower than the DB reality, or handle as needed
        // Actually, allowing negative might be necessary to fix errors. Let's allow it in case they want to "remove" a DB counted one.
        $newManualOffset = $desiredTotal - $derivedCount;

        $stmt = $pdo->prepare("UPDATE members SET manual_championships_count = ? WHERE id = ?");
        $stmt->execute([$newManualOffset, $memberId]);

        // Sync to JSON for scanners
        self::syncToJson($memberId);

        // Refresh Cache
        $stmt = $pdo->prepare("SELECT permanent_code FROM members WHERE id = ?");
        $stmt->execute([$memberId]);
        $code = $stmt->fetchColumn();
        if ($code)
            BadgeCacheService::refresh($code);

        auditLog('update_stats', 'members', $memberId, null, "Manual Championships: " . ($championshipsCount ?: '0'), null);

        return true;
    }

    /**
     * Update manual statistics for a member (Rounds)
     */
    public static function updateManualRounds($memberId, $roundsCount)
    {
        $pdo = db();

        // Auto-add manual_rounds_count if it doesn't exist
        try {
            $pdo->exec("ALTER TABLE members ADD COLUMN manual_rounds_count INTEGER DEFAULT 0");
        } catch (\Exception $e) {
        }

        $desiredTotal = $roundsCount === '' ? 0 : (int) $roundsCount;

        // Calculate derived rounds count without the manual override
        $stmt = $pdo->prepare("SELECT permanent_code FROM members WHERE id = ?");
        $stmt->execute([$memberId]);
        $permanentCode = $stmt->fetchColumn();

        $identifiers = [$permanentCode];
        $stmtReg = $pdo->prepare("SELECT session_badge_token FROM registrations WHERE member_id = ? AND session_badge_token IS NOT NULL");
        $stmtReg->execute([$memberId]);
        while ($row = $stmtReg->fetch(PDO::FETCH_ASSOC)) {
            $identifiers[] = $row['session_badge_token'];
        }

        $roundsPlaceholder = implode(',', array_fill(0, count($identifiers), '?'));
        $stmtRounds = $pdo->prepare("
            SELECT COUNT(*) FROM round_logs rl
            JOIN participants p ON rl.participant_id = p.id
            WHERE (p.registration_code = ? OR p.badge_id IN ($roundsPlaceholder))
            AND rl.action = 'enter'
        ");
        $roundsParams = array_merge([$permanentCode], $identifiers);
        $stmtRounds->execute($roundsParams);
        $derivedRoundsDB = (int) $stmtRounds->fetchColumn();

        // Add JSON historical rounds
        $derivedRoundsJSON = 0;
        $membersFile = __DIR__ . '/../admin/data/members.json';
        if (file_exists($membersFile)) {
            $mJson = json_decode(file_get_contents($membersFile), true) ?? [];
            if (isset($mJson[$permanentCode]) && !empty($mJson[$permanentCode]['total_rounds_all_time'])) {
                $derivedRoundsJSON = intval($mJson[$permanentCode]['total_rounds_all_time']);
            }
        }

        $derivedCount = $derivedRoundsDB + $derivedRoundsJSON;
        $newManualOffset = $desiredTotal - $derivedCount;

        $stmt = $pdo->prepare("UPDATE members SET manual_rounds_count = ? WHERE id = ?");
        $stmt->execute([$newManualOffset, $memberId]);

        // Sync to JSON for scanners
        self::syncToJson($memberId);

        // Refresh Cache
        $stmt = $pdo->prepare("SELECT permanent_code FROM members WHERE id = ?");
        $stmt->execute([$memberId]);
        $code = $stmt->fetchColumn();
        if ($code)
            BadgeCacheService::refresh($code);

        auditLog('update_stats', 'members', $memberId, null, "Manual Rounds: " . ($roundsCount ?: '0'), null);

        return true;
    }

    /**
     * Check if member has blocker notes
     * 
     * @param string $permanentCode Member's permanent code
     * @return bool
     */
    public static function hasBlockerNotes($permanentCode)
    {
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM notes n
            JOIN participants p ON n.participant_id = p.id
            WHERE p.registration_code = ? 
            AND n.note_type = 'blocker' 
            AND n.is_resolved = 0
        ");
        $stmt->execute([$permanentCode]);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Get or create member (for registration)
     * 
     * @param string $phone Phone number (will be normalized)
     * @param string $name Full name
     * @param string|null $governorate Governorate
     * Get or create member (Internal Primitive)
     * 
     * WARNING: For bulk imports, use importMember() instead to ensure 
     * correct source tracking and validation.
     * 
     * @param string $phone Phone number (will be normalized)
     * @param string $name Full name
     * @param string|null $governorate Governorate
     * @return array Member data
     * @throws InvalidArgumentException If phone is invalid
     */
    public static function getOrCreateMember($phone, $name, $governorate = null)
    {
        $pdo = db();
        $phone = normalizePhone($phone); // Will throw if invalid

        // Check existing member
        $stmt = $pdo->prepare("SELECT * FROM members WHERE phone = ?");
        $stmt->execute([$phone]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($member) {
            // Update name/instagram if different
            if ($member['name'] !== $name) {
                $pdo->prepare("UPDATE members SET name = ? WHERE id = ?")
                    ->execute([$name, $member['id']]);
                $member['name'] = $name;
            }
            return $member;
        }

        // Create new member with temporary code
        $stmt = $pdo->prepare("
            INSERT INTO members (phone, name, governorate, permanent_code)
            VALUES (?, ?, ?, 'TEMP')
        ");
        $stmt->execute([$phone, $name, $governorate]);
        $memberId = $pdo->lastInsertId();

        // Generate permanent code based on ID (deterministic)
        $permanentCode = generatePermanentCode($memberId);
        $pdo->prepare("UPDATE members SET permanent_code = ? WHERE id = ?")
            ->execute([$permanentCode, $memberId]);

        // Audit log
        auditLog('create', 'member', $memberId, null, json_encode([
            'phone' => $phone,
            'name' => $name,
            'permanent_code' => $permanentCode
        ]));

        return [
            'id' => $memberId,
            'phone' => $phone,
            'name' => $name,
            'governorate' => $governorate,
            'permanent_code' => $permanentCode,
            'is_active' => 1,
            'account_activated' => 0
        ];
    }

    /**
     * Create registration for member in championship
     * 
     * @param int $memberId Member ID
     * @param array $data Registration data (car_type, plate, etc.)
     * @param int|null $championshipId Championship ID (defaults to current)
     * @return array Registration data
     */
    public static function createRegistration($memberId, $data, $championshipId = null)
    {
        $pdo = db();
        $championshipId = $championshipId ?? getCurrentChampionshipId();

        // Get next wasel number
        $stmt = $pdo->prepare("
            SELECT COALESCE(MAX(wasel), 0) + 1 
            FROM registrations 
            WHERE championship_id = ?
        ");
        $stmt->execute([$championshipId]);
        $nextWasel = $stmt->fetchColumn();

        $champName = 'البطولة الحالية';
        $frameSettingsFile = __DIR__ . '/../admin/data/frame_settings.json';
        if (file_exists($frameSettingsFile)) {
            $fs = json_decode(file_get_contents($frameSettingsFile), true);
            if (!empty($fs['form_titles']['sub_title'])) {
                $champName = $fs['form_titles']['sub_title'];
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO registrations (
                member_id, championship_id, wasel,
                car_type, car_year, car_color,
                plate_governorate, plate_letter, plate_number,
                engine_size, participation_type, status, championship_name
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
        ");

        $stmt->execute([
            $memberId,
            $championshipId,
            $nextWasel,
            $data['car_type'] ?? null,
            $data['car_year'] ?? null,
            $data['car_color'] ?? null,
            $data['plate_governorate'] ?? null,
            $data['plate_letter'] ?? null,
            $data['plate_number'] ?? null,
            $data['engine_size'] ?? null,
            $data['participation_type'] ?? null,
            $champName
        ]);

        // AUTO-ACTIVATE MEMBER ACCOUNT
        // If a member registers themselves (provides data + photo in registration flow), 
        // they are effectively "Confirmed".
        $pdo->prepare("UPDATE members SET account_activated = 1 WHERE id = ? AND account_activated = 0")
            ->execute([$memberId]);


        $registrationId = $pdo->lastInsertId();

        // Audit log
        auditLog('create', 'registration', $registrationId, null, json_encode([
            'member_id' => $memberId,
            'championship_id' => $championshipId,
            'wasel' => $nextWasel
        ]));

        return [
            'id' => $registrationId,
            'member_id' => $memberId,
            'championship_id' => $championshipId,
            'wasel' => $nextWasel,
            'status' => 'pending'
        ];
    }

    /**
     * Approve registration
     * 
     * @param int $registrationId Registration ID
     * @param int|null $userId User approving
     * @return array Updated registration
     */
    public static function approveRegistration($registrationId, $userId = null)
    {
        $pdo = db();

        // Generate session badge token
        $token = generateSessionBadgeToken($registrationId);

        $stmt = $pdo->prepare("
            UPDATE registrations 
            SET status = 'approved', session_badge_token = ?
            WHERE id = ?
        ");
        $stmt->execute([$token, $registrationId]);

        // Audit log
        auditLog(
            'approve',
            'registration',
            $registrationId,
            json_encode(['status' => 'pending']),
            json_encode(['status' => 'approved']),
            $userId
        );

        // Get updated registration
        $stmt = $pdo->prepare("SELECT * FROM registrations WHERE id = ?");
        $stmt->execute([$registrationId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get all members (for admin list)
     * 
     * @param int $limit
     * @param int $offset
     * @param string $search Search query (name/phone/code)
     * @return array
     */
    public static function getAllMembers($limit = 100, $offset = 0, $search = '')
    {
        $pdo = db();

        $sql = "SELECT m.*, 
                (SELECT COUNT(*) FROM registrations r WHERE r.member_id = m.id AND r.status = 'approved') as championships_count,
                (SELECT created_at FROM registrations r WHERE r.member_id = m.id ORDER BY created_at ASC LIMIT 1) as first_registered
                FROM members m 
                WHERE m.is_active = 1";

        $params = [];
        if (!empty($search)) {
            $sql .= " AND (m.name LIKE ? OR m.phone LIKE ? OR m.permanent_code LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $sql .= " ORDER BY m.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Add warning to member
     * 
     * @param int $memberId Member ID
     * @param string $text Warning text
     * @param string $severity low|medium|high
     * @param string|null $expiresAt YYYY-MM-DD
     * @param int|null $championshipId NULL for global warning
     * @param int|null $userId User creating warning
     * @return int Warning ID
     */
    public static function addWarning($memberId, $text, $severity = 'low', $expiresAt = null, $championshipId = null, $userId = null)
    {
        $pdo = db();

        $stmt = $pdo->prepare("
            INSERT INTO warnings (member_id, championship_id, warning_text, severity, expires_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$memberId, $championshipId, $text, $severity, $expiresAt, $userId]);
        $warningId = $pdo->lastInsertId();

        // Refresh Cache
        $stmt = $pdo->prepare("SELECT permanent_code FROM members WHERE id = ?");
        $stmt->execute([$memberId]);
        $code = $stmt->fetchColumn();
        if ($code)
            BadgeCacheService::refresh($code);

        // Audit log
        auditLog('create', 'warning', $warningId, null, json_encode([
            'member_id' => $memberId,
            'text' => $text,
            'severity' => $severity,
            'expires_at' => $expiresAt
        ]), $userId);

        return $warningId;
    }

    /**
     * Resolve warning
     * 
     * @param int $warningId Warning ID
     * @param int|null $userId User resolving
     * @return bool Success
     */
    public static function resolveWarning($warningId, $userId = null)
    {
        $pdo = db();

        $stmt = $pdo->prepare("
            UPDATE warnings 
            SET is_resolved = 1, resolved_by = ?, resolved_at = datetime('now', '+3 hours')
            WHERE id = ?
        ");
        $result = $stmt->execute([$userId, $warningId]);

        auditLog('resolve', 'warning', $warningId, null, null, $userId);

        return $result;
    }

    /**
     * Send activation message to member
     * 
     * @param int $memberId Member ID
     * @return bool Success
     */
    public static function sendActivation($memberId)
    {
        $pdo = db();

        $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ?");
        $stmt->execute([$memberId]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$member) {
            return false;
        }

        $message = "🏎️ *مرحباً {$member['name']}*\n\n";
        $message .= "تم إضافتك في نظام نادي بلاد الرافدين للسيارات!\n\n";
        $message .= "🔑 *كود التسجيل الخاص بك:*\n";
        $message .= "`{$member['permanent_code']}`\n\n";
        $message .= "استخدم هذا الكود للتسجيل في البطولات القادمة.\n";
        $message .= "📱 رابط التسجيل: https://drb.iq";

        // TODO: Integrate with actual WhatsApp sender
        // $result = sendWhatsAppMessage($member['phone'], $message);

        // Mark as activated
        $stmt = $pdo->prepare("
            UPDATE members 
            SET account_activated = 1, activation_sent_at = datetime('now', '+3 hours')
            WHERE id = ?
        ");
        $stmt->execute([$memberId]);

        auditLog('activate', 'member', $memberId);

        return true;
    }

    /**
     * Get statistics for member
     * 
     * @param int $memberId Member ID
     * @return array Statistics
     */
    public static function getStatistics($memberId)
    {
        $pdo = db();

        // Championships participated
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT championship_id) 
            FROM registrations 
            WHERE member_id = ? AND status = 'approved' AND is_active = 1
        ");
        $stmt->execute([$memberId]);
        $championships = $stmt->fetchColumn();

        // Total rounds entered
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM round_logs rl
            JOIN participants p ON rl.participant_id = p.id
            JOIN members m ON p.registration_code = m.permanent_code
            WHERE m.id = ? AND rl.action = 'enter'
        ");
        $stmt->execute([$memberId]);
        $rounds = $stmt->fetchColumn();

        // Active warnings
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM warnings 
            WHERE member_id = ? AND is_resolved = 0
        ");
        $stmt->execute([$memberId]);
        $warnings = $stmt->fetchColumn();

        // Total warnings (resolved + active)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM warnings WHERE member_id = ?");
        $stmt->execute([$memberId]);
        $totalWarnings = $stmt->fetchColumn();

        return [
            'championships_participated' => (int) $championships,
            'rounds_entered' => (int) $rounds,
            'active_warnings' => (int) $warnings,
            'total_warnings' => (int) $totalWarnings
        ];
    }



    /**
     * Import member safely (Gatekeeper for imports)
     * 
     * @param array $data Member data (phone, name, governorate)
     * @param string $source Source constant (MemberService::SOURCE_*)
     * @param string|null $batchId Batch ID for audit
     * @return array Member data
     */
    public static function importMember($data, $source = self::SOURCE_IMPORT_STANDARD, $batchId = null)
    {
        $pdo = db();

        // 1. Validate & Normalize
        $phone = normalizePhone($data['phone']);
        $name = trim($data['name']);
        if (empty($name))
            throw new Exception("الاسم مطلوب");

        // 2. Internal Get/Create
        // Note: New members are created with account_activated = 0 by default
        $member = self::getOrCreateMember($phone, $name, $data['governorate'] ?? null);

        // 3. Update Source & Batch Info (with auto-migration for missing columns)
        try {
            $stmt = $pdo->prepare("
                UPDATE members 
                SET source = ?, import_batch_id = ? 
                WHERE id = ?
            ");
            $stmt->execute([$source, $batchId, $member['id']]);
        } catch (PDOException $e) {
            // If columns don't exist, add them (auto-migration)
            if (strpos($e->getMessage(), 'no such column') !== false || strpos($e->getMessage(), 'Unknown column') !== false) {
                // Add missing columns
                try {
                    $pdo->exec("ALTER TABLE members ADD COLUMN source TEXT");
                } catch (Exception $ignore) {
                }
                try {
                    $pdo->exec("ALTER TABLE members ADD COLUMN import_batch_id TEXT");
                } catch (Exception $ignore) {
                }

                // Retry the update
                $stmt = $pdo->prepare("UPDATE members SET source = ?, import_batch_id = ? WHERE id = ?");
                $stmt->execute([$source, $batchId, $member['id']]);
            } else {
                throw $e; // Re-throw if it's a different error
            }
        }

        // Finalize with sync
        try {
            self::syncToJson($member['id']);
        } catch (Exception $e) {
        }

        return $member;
    }

    /**
     * Sync Member Data to Legacy JSON files (for scanners)
     * 
     * @param int $memberId
     * @return bool
     */
    public static function syncToJson($memberId)
    {
        $pdo = db();

        // 1. Fetch Member Core
        $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ?");
        $stmt->execute([$memberId]);
        $member = $stmt->fetch();
        if (!$member)
            return false;

        $code = $member['permanent_code'] ?? '';
        if (empty($code))
            return false;

        // 2. Fetch Current Registration
        $champId = getCurrentChampionshipId();
        $stmt = $pdo->prepare("SELECT * FROM registrations WHERE member_id = ? AND championship_id = ? AND is_active = 1");
        $stmt->execute([$memberId, $champId]);
        $reg = $stmt->fetch();

        // 3. Update members.json WITH FILE LOCKING to prevent race conditions
        $membersFile = __DIR__ . '/../admin/data/members.json';
        $membersLockFile = __DIR__ . '/../admin/data/members.lock';
        $lockHandle = fopen($membersLockFile, 'w');
        if ($lockHandle) {
            flock($lockHandle, LOCK_EX); // Wait for exclusive lock
        }

        $membersData = [];
        if (file_exists($membersFile)) {
            $membersData = json_decode(file_get_contents($membersFile), true) ?? [];
        }

        $memberRecord = $membersData[$code] ?? [
            'registration_code' => $code,
            'first_registered' => $member['created_at'],
            'championships_participated' => $member['championships_participated'] ?? 0,
            'manual_rounds_count' => $member['manual_rounds_count'] ?? 0,
            'images' => []
        ];

        // Sync Fields
        $memberRecord['manual_rounds_count'] = $member['manual_rounds_count'] ?? 0;
        $memberRecord['total_historical_championships'] = $member['championships_participated'] ?? 0;

        // Sync Fields
        $memberRecord['full_name'] = $member['name'];
        $memberRecord['phone'] = $member['phone'];
        $memberRecord['country_code'] = '+964';
        $memberRecord['governorate'] = $member['governorate'];
        $memberRecord['instagram'] = $member['instagram'] ?? '';
        $memberRecord['last_active'] = date('Y-m-d H:i:s');

        // Car Info from Member or Registration
        $sourceCar = $reg ?: $member;
        $memberRecord['car_type'] = $sourceCar['car_type'] ?? $member['last_car_type'] ?? '';
        $memberRecord['car_year'] = $sourceCar['car_year'] ?? $member['last_car_year'] ?? '';
        $memberRecord['car_color'] = $sourceCar['car_color'] ?? $member['last_car_color'] ?? '';
        $memberRecord['engine_size'] = $sourceCar['engine_size'] ?? $member['last_engine_size'] ?? '';
        $memberRecord['plate_governorate'] = $sourceCar['plate_governorate'] ?? $member['last_plate_governorate'] ?? '';
        $memberRecord['plate_letter'] = $sourceCar['plate_letter'] ?? $member['last_plate_letter'] ?? '';
        $memberRecord['plate_number'] = $sourceCar['plate_number'] ?? $member['last_plate_number'] ?? '';
        $memberRecord['participation_type'] = $sourceCar['participation_type'] ?? $member['last_participation_type'] ?? '';

        // Plate Full
        $plateParts = array_filter([$memberRecord['plate_governorate'], $memberRecord['plate_letter'], $memberRecord['plate_number']]);
        $memberRecord['plate_full'] = implode(' - ', $plateParts);

        // Personal Photo (Prefer registration photo if available)
        $finalPhoto = !empty($reg['personal_photo']) ? $reg['personal_photo'] : (!empty($member['personal_photo']) ? $member['personal_photo'] : '');

        if (!empty($finalPhoto)) {
            $memberRecord['personal_photo'] = $finalPhoto;
        }

        // Detailed Images Sync
        $latestReg = $reg ?: null;

        $resolveImagePath = function ($dbValue, $jsonValue) {
            if (!empty($dbValue))
                return (string) $dbValue;
            if (!empty($jsonValue) && file_exists(__DIR__ . '/../' . ltrim($jsonValue, '/'))) {
                return (string) $jsonValue;
            }
            return '';
        };

        $memberRecord['images'] = [
            'personal_photo' => $resolveImagePath($finalPhoto, $memberRecord['images']['personal_photo'] ?? ''),
            'front_image' => $resolveImagePath($latestReg['front_image'] ?? null, $memberRecord['images']['front_image'] ?? ''),
            'side_image' => $resolveImagePath($latestReg['side_image'] ?? null, $memberRecord['images']['side_image'] ?? ''),
            'back_image' => $resolveImagePath($latestReg['back_image'] ?? null, $memberRecord['images']['back_image'] ?? ''),
            'edited_image' => $resolveImagePath($latestReg['edited_image'] ?? null, $memberRecord['images']['edited_image'] ?? ''),
            'acceptance_image' => $resolveImagePath($latestReg['acceptance_image'] ?? null, $memberRecord['images']['acceptance_image'] ?? ''),
            'national_id_front' => $resolveImagePath($member['national_id_front'] ?? null, $memberRecord['images']['national_id_front'] ?? ''),
            'national_id_back' => $resolveImagePath($member['national_id_back'] ?? null, $memberRecord['images']['national_id_back'] ?? ''),
            'license_front' => $resolveImagePath($latestReg['license_front'] ?? null, $memberRecord['images']['license_front'] ?? ''),
            'license_back' => $resolveImagePath($latestReg['license_back'] ?? null, $memberRecord['images']['license_back'] ?? '')
        ];

        $membersData[$code] = $memberRecord;
        file_put_contents($membersFile, json_encode($membersData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Release lock
        if ($lockHandle) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }

        // 4. Update data.json (registrations for scanners)
        if ($reg) {
            $dataFile = __DIR__ . '/../admin/data/data.json';
            $dataJson = [];
            if (file_exists($dataFile)) {
                $dataJson = json_decode(file_get_contents($dataFile), true) ?? [];
            }

            $found = false;
            foreach ($dataJson as &$d) {
                if (($d['registration_code'] ?? '') === $code || ($d['wasel'] ?? '') == $reg['wasel']) {
                    // Update existing
                    $d['full_name'] = $member['name'];
                    $d['phone'] = $member['phone'];
                    $d['governorate'] = $member['governorate'];
                    $d['car_type'] = $reg['car_type'];
                    $d['car_year'] = $reg['car_year'];
                    $d['car_color'] = $reg['car_color'];
                    $d['plate_governorate'] = $reg['plate_governorate'];
                    $d['plate_letter'] = $reg['plate_letter'];
                    $d['plate_number'] = $reg['plate_number'];
                    $d['plate_full'] = $memberRecord['plate_full'];
                    $d['participation_type'] = $reg['participation_type'];
                    $d['status'] = $reg['status'];
                    $d['personal_photo'] = !empty($finalPhoto) ? $finalPhoto : ($d['personal_photo'] ?? '');
                    $d['front_image'] = !empty($reg['front_image']) ? (string) $reg['front_image'] : ($d['front_image'] ?? '');
                    $d['side_image'] = !empty($reg['side_image']) ? (string) $reg['side_image'] : ($d['side_image'] ?? '');
                    $d['back_image'] = !empty($reg['back_image']) ? (string) $reg['back_image'] : ($d['back_image'] ?? '');
                    $d['edited_image'] = !empty($reg['edited_image']) ? (string) $reg['edited_image'] : ($d['edited_image'] ?? '');
                    $d['acceptance_image'] = !empty($reg['acceptance_image']) ? (string) $reg['acceptance_image'] : ($d['acceptance_image'] ?? '');
                    $d['national_id_front'] = !empty($member['national_id_front']) ? (string) $member['national_id_front'] : ($d['national_id_front'] ?? '');
                    $d['national_id_back'] = !empty($member['national_id_back']) ? (string) $member['national_id_back'] : ($d['national_id_back'] ?? '');
                    // Preserve existing license images — don't overwrite with empty
                    $newLicFront = $memberRecord['images']['license_front'] ?? '';
                    $newLicBack = $memberRecord['images']['license_back'] ?? '';
                    $d['license_front'] = !empty($newLicFront) ? $newLicFront : ($d['license_front'] ?? ($d['images']['license_front'] ?? ''));
                    $d['license_back'] = !empty($newLicBack) ? $newLicBack : ($d['license_back'] ?? ($d['images']['license_back'] ?? ''));

                    // Preserve existing id_front/id_back from data.json
                    $existingIdFront = $d['id_front'] ?? ($d['images']['id_front'] ?? '');
                    $existingIdBack = $d['id_back'] ?? ($d['images']['id_back'] ?? '');

                    // Add structured images array for dashboard
                    $d['images'] = [
                        'personal_photo' => $d['personal_photo'],
                        'front_image' => $d['front_image'],
                        'side_image' => $d['side_image'],
                        'back_image' => $d['back_image'],
                        'edited_image' => $d['edited_image'],
                        'acceptance_image' => $d['acceptance_image'],
                        'national_id_front' => $d['national_id_front'],
                        'national_id_back' => $d['national_id_back'],
                        'id_front' => $existingIdFront,
                        'id_back' => $existingIdBack,
                        'license_front' => $d['license_front'],
                        'license_back' => $d['license_back']
                    ];
                    $d['images'] = array_filter($d['images']); // Remove empty paths

                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $dataNew = [
                    'wasel' => $reg['wasel'],
                    'registration_code' => $code,
                    'full_name' => $member['name'],
                    'phone' => $member['phone'],
                    'country_code' => '+964',
                    'governorate' => $member['governorate'],
                    'car_type' => $reg['car_type'],
                    'car_year' => $reg['car_year'],
                    'car_color' => $reg['car_color'],
                    'plate_governorate' => $reg['plate_governorate'],
                    'plate_letter' => $reg['plate_letter'],
                    'plate_number' => $reg['plate_number'],
                    'plate_full' => $memberRecord['plate_full'],
                    'participation_type' => $reg['participation_type'],
                    'status' => $reg['status'],
                    'registration_date' => $reg['created_at'],
                    'personal_photo' => $finalPhoto,
                    'front_image' => $reg['front_image'] ?? '',
                    'side_image' => $reg['side_image'] ?? '',
                    'back_image' => $reg['back_image'] ?? '',
                    'edited_image' => $reg['edited_image'] ?? '',
                    'acceptance_image' => $reg['acceptance_image'] ?? '',
                    'national_id_front' => $member['national_id_front'] ?? '',
                    'national_id_back' => $member['national_id_back'] ?? '',
                    'license_front' => $memberRecord['images']['license_front'] ?? '',
                    'license_back' => $memberRecord['images']['license_back'] ?? '',
                    'badge_token' => $code
                ];

                $dataNew['images'] = array_filter([
                    'personal_photo' => $dataNew['personal_photo'],
                    'front_image' => $dataNew['front_image'],
                    'side_image' => $dataNew['side_image'],
                    'back_image' => $dataNew['back_image'],
                    'edited_image' => $dataNew['edited_image'],
                    'acceptance_image' => $dataNew['acceptance_image'],
                    'national_id_front' => $dataNew['national_id_front'],
                    'national_id_back' => $dataNew['national_id_back'],
                    'license_front' => $dataNew['license_front'],
                    'license_back' => $dataNew['license_back']
                ]);

                $dataJson[] = $dataNew;
            }

            file_put_contents($dataFile, json_encode($dataJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return true;
    }

    /**
     * Ensure a JSON registration has a mirror in SQLite
     * 
     * @param array $reg Registration data from JSON
     * @return int|bool SQLite Registration ID or false
     */
    public static function ensureSQLiteRecord($reg)
    {
        $pdo = db();

        // 1. Check if registration already exists by wasel (within same championship)
        $champId = $reg['championship_id'] ?? null;
        if (!$champId || !is_numeric($champId)) {
            $champId = getCurrentChampionshipId();
        } else {
            $champId = (int) $champId;
        }
        $wasel = $reg['wasel'] ?? null;

        if (!$wasel)
            return false;

        $stmt = $pdo->prepare("SELECT id FROM registrations WHERE wasel = ? AND championship_id = ?");
        $stmt->execute([$wasel, $champId]);
        $existingId = $stmt->fetchColumn();

        if ($existingId) {
            // Update existing record from JSON data (critical for status changes AND re-registrations)
            $stmt = $pdo->prepare("
                UPDATE registrations 
                SET status = ?, 
                    acceptance_image = COALESCE(?, acceptance_image),
                    session_badge_token = COALESCE(?, session_badge_token),
                    car_type = COALESCE(?, car_type),
                    car_year = COALESCE(?, car_year),
                    car_color = COALESCE(?, car_color),
                    plate_number = COALESCE(?, plate_number),
                    plate_letter = COALESCE(?, plate_letter),
                    plate_governorate = COALESCE(?, plate_governorate),
                    engine_size = COALESCE(?, engine_size),
                    participation_type = COALESCE(?, participation_type),
                    personal_photo = COALESCE(?, personal_photo),
                    front_image = COALESCE(?, front_image),
                    back_image = COALESCE(?, back_image),
                    license_front = COALESCE(?, license_front),
                    license_back = COALESCE(?, license_back)
                WHERE id = ?
            ");
            $stmt->execute([
                $reg['status'] ?? 'pending',
                $reg['acceptance_image'] ?? ($reg['images']['acceptance_image'] ?? null),
                $reg['session_badge_token'] ?? $reg['badge_token'] ?? null,
                $reg['car_type'] ?? null,
                $reg['car_year'] ?? null,
                $reg['car_color'] ?? null,
                $reg['plate_number'] ?? null,
                $reg['plate_letter'] ?? null,
                $reg['plate_governorate'] ?? null,
                $reg['engine_size'] ?? null,
                $reg['participation_type'] ?? null,
                $reg['personal_photo'] ?? ($reg['images']['personal_photo'] ?? null),
                $reg['front_image'] ?? ($reg['images']['front_image'] ?? null),
                $reg['back_image'] ?? ($reg['images']['back_image'] ?? null),
                $reg['license_front'] ?? ($reg['images']['license_front'] ?? null),
                $reg['license_back'] ?? ($reg['images']['license_back'] ?? null),
                $existingId
            ]);

            // Sync to Participants Cache (Scanner Table) as well since we are here
            try {
                $stmtPart = $pdo->prepare("
                    UPDATE participants SET 
                        registration_code = COALESCE(?, registration_code),
                        name = COALESCE(?, name),
                        car_type = COALESCE(?, car_type),
                        car_color = COALESCE(?, car_color),
                        plate = COALESCE(?, plate)
                    WHERE badge_id = ?
                ");
                $stmtPart->execute([
                    $reg['registration_code'] ?? null,
                    $reg['full_name'] ?? $reg['name'] ?? null,
                    $reg['car_type'] ?? null,
                    $reg['car_color'] ?? null,
                    $reg['plate_governorate'] ?? $reg['plate_full'] ?? null,
                    $wasel // Using wasel as badge_id for scanner simplicity
                ]);
            } catch (Exception $ePart) {
                // Ignore, participant might not exist
            }

            // Also update member's instagram if available
            $regInstagram = $reg['instagram'] ?? '';
            if (!empty($regInstagram)) {
                try {
                    // Find member_id from registration
                    $memStmt = $pdo->prepare("SELECT member_id FROM registrations WHERE id = ?");
                    $memStmt->execute([$existingId]);
                    $memId = $memStmt->fetchColumn();
                    if ($memId) {
                        $pdo->prepare("UPDATE members SET instagram = COALESCE(?, instagram) WHERE id = ?")
                            ->execute([$regInstagram, $memId]);
                    }
                } catch (Exception $e) {
                }
            }

            return $existingId;
        }

        // 2. Not in SQLite. Create Member first.
        try {
            $champName = 'البطولة الحالية';
            $frameSettingsFile = __DIR__ . '/../admin/data/frame_settings.json';
            if (file_exists($frameSettingsFile)) {
                $fs = json_decode(file_get_contents($frameSettingsFile), true);
                if (!empty($fs['form_titles']['sub_title'])) {
                    $champName = $fs['form_titles']['sub_title'];
                }
            }
            $phone = normalizePhone($reg['phone']);
            $name = $reg['full_name'] ?? $reg['name'] ?? 'Unknown Member';
            $member = self::getOrCreateMember($phone, $name, $reg['governorate'] ?? null);

            // 3. Create Registration
            $stmt = $pdo->prepare("
                INSERT INTO registrations (
                    member_id, championship_id, wasel,
                    car_type, car_year, car_color,
                    plate_governorate, plate_letter, plate_number,
                    engine_size, participation_type, status,
                    personal_photo, front_image, side_image, back_image,
                    edited_image, acceptance_image, session_badge_token, championship_name,
                    license_front, license_back
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $member['id'],
                $champId,
                $wasel,
                $reg['car_type'] ?? null,
                $reg['car_year'] ?? null,
                $reg['car_color'] ?? null,
                $reg['plate_governorate'] ?? null,
                $reg['plate_letter'] ?? null,
                $reg['plate_number'] ?? null,
                $reg['engine_size'] ?? null,
                $reg['participation_type'] ?? null,
                $reg['status'] ?? 'pending',
                $reg['personal_photo'] ?? ($reg['images']['personal_photo'] ?? null),
                $reg['front_image'] ?? ($reg['images']['front_image'] ?? null),
                $reg['side_image'] ?? ($reg['images']['side_image'] ?? null),
                $reg['back_image'] ?? ($reg['images']['back_image'] ?? null),
                $reg['edited_image'] ?? ($reg['images']['edited_image'] ?? null),
                $reg['acceptance_image'] ?? ($reg['images']['acceptance_image'] ?? null),
                $reg['session_badge_token'] ?? $reg['badge_token'] ?? null,
                $champName,
                $reg['license_front'] ?? ($reg['images']['license_front'] ?? null),
                $reg['license_back'] ?? ($reg['images']['license_back'] ?? null)
            ]);

            $newId = $pdo->lastInsertId();

            // 4. Update Member fields (instagram, governorate, etc.)
            $updateFields = [];
            $updateValues = [];
            if (!empty($reg['registration_code']) && $member['permanent_code'] === 'TEMP') {
                $updateFields[] = 'permanent_code = ?';
                $updateValues[] = $reg['registration_code'];
            }
            $regInstagram = $reg['instagram'] ?? '';
            if (!empty($regInstagram)) {
                $updateFields[] = 'instagram = ?';
                $updateValues[] = $regInstagram;
            }
            if (!empty($updateFields)) {
                $updateValues[] = $member['id'];
                $pdo->prepare("UPDATE members SET " . implode(', ', $updateFields) . " WHERE id = ?")
                    ->execute($updateValues);
            }

            // 5. Sync to Participants Cache (Scanner Table)
            try {
                $stmtPart = $pdo->prepare("
                    INSERT INTO participants (badge_id, registration_code, wasel, name, car_type, car_color, plate, phone)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ON CONFLICT(badge_id) DO UPDATE SET
                        registration_code = excluded.registration_code,
                        wasel = excluded.wasel,
                        name = excluded.name,
                        car_type = excluded.car_type,
                        car_color = excluded.car_color,
                        plate = excluded.plate,
                        phone = excluded.phone
                ");
                $stmtPart->execute([
                    $wasel, // Use wasel as badge_id for scanner simplicity
                    $reg['registration_code'] ?? '',
                    $wasel,
                    $name,
                    $reg['car_type'] ?? '',
                    $reg['car_color'] ?? '',
                    $reg['plate_governorate'] ?? $reg['plate_full'] ?? '',
                    $reg['phone'] ?? ''
                ]);
            } catch (Exception $ePart) {
                // Non-critical, but log it
                error_log("Failed to sync participant cache for wasel $wasel: " . $ePart->getMessage());
            }

            return $newId;

        } catch (Exception $e) {
            error_log("Failed to ensure SQLite record for wasel $wasel: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync by Wasel ID (Helper for older scripts)
     */
    public static function syncToJsonByWasel($wasel)
    {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT member_id FROM registrations WHERE wasel = ? LIMIT 1");
        $stmt->execute([$wasel]);
        $memberId = $stmt->fetchColumn();
        if ($memberId) {
            return self::syncToJson($memberId);
        }
        return false;
    }
}
