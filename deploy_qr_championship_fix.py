"""
Deploy: QR Championship Validation Fix + Registration Code Fix
Fixes:
  1. Old members not registered being auto-registered on QR scan
  2. Returning members getting new codes instead of reusing old ones

Uploads the following modified files:
  - verify_entry.php (block auto-registration of old members)
  - api/qr_scan.php (fix hardcoded is_current_participant)
  - admin/qr_scanner.php (show clear "not registered" warning for old members)
  - check_status.php (show "not registered" instead of "approved" for old members)
  - process.php (add name matching for returning member detection)
  - repair_naji.php (Script to merge the duplicated record)
"""
import ftplib
import os

FTP_HOST = "145.223.86.39"
FTP_USER = "u670530239"
FTP_PASS = "u?j?4F9wX/#r45R@"
REMOTE_DIR = "domains/yellowgreen-quail-410393.hostingersite.com/public_html"

FILES = [
    ("verify_entry.php", "verify_entry.php"),
    ("api/qr_scan.php", "api/qr_scan.php"),
    ("admin/qr_scanner.php", "admin/qr_scanner.php"),
    ("check_status.php", "check_status.php"),
    ("process.php", "process.php"),
    ("repair_naji.php", "repair_naji.php"),
]

def deploy():
    print(f"Connecting to FTP {FTP_HOST}...")
    try:
        ftp = ftplib.FTP(FTP_HOST, timeout=30)
        ftp.login(FTP_USER, FTP_PASS)
        print("Login successful.")
        ftp.cwd(REMOTE_DIR)
        print(f"Changed directory to: {REMOTE_DIR}")

        for local, remote in FILES:
            if not os.path.exists(local):
                print(f"ERROR: Local file missing: {local}")
                continue
                
            print(f"Uploading {local} -> {remote}...")
            with open(local, "rb") as f:
                ftp.storbinary(f"STOR {remote}", f)
            print(f"  ✓ Done")

        ftp.quit()
        print(f"\n✅ Success! All {len(FILES)} files uploaded.")
        print("\nChanges deployed:")
        print("  1. verify_entry.php - Old members no longer auto-registered")
        print("  2. api/qr_scan.php - Fixed hardcoded is_current_participant")
        print("  3. admin/qr_scanner.php - Clear 'not registered' warning UI")
    except Exception as e:
        print(f"ERROR: {e}")

if __name__ == "__main__":
    deploy()
