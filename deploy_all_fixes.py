"""
Deploy: Dashboard Fix + Member ID Fix
Uploads the following modified files:
  - verify_entry.php (enriched data creation)
  - dashboard.php (resilient display)
  - repair_incomplete_records.php (data repair script)
  - process.php (member_id fix)
  - admin/export_members.php (member_id fix)
"""
import ftplib
import os

FTP_HOST = "145.223.86.39"
FTP_USER = "u670530239"
FTP_PASS = "u?j?4F9wX/#r45R@"
REMOTE_DIR = "domains/yellowgreen-quail-410393.hostingersite.com/public_html"

FILES = [
    ("verify_entry.php", "verify_entry.php"),
    ("dashboard.php", "dashboard.php"),
    ("repair_incomplete_records.php", "repair_incomplete_records.php"),
    ("process.php", "process.php"),
    ("admin/export_members.php", "admin/export_members.php"),
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

        ftp.quit()
        print(f"\nSuccess! All files uploaded.")
        print("\nNEXT STEPS ON SERVER:")
        print("1. Run: php repair_incomplete_records.php")
        print("2. Delete repair_incomplete_records.php after use.")
    except Exception as e:
        print(f"ERROR: {e}")

if __name__ == "__main__":
    deploy()
