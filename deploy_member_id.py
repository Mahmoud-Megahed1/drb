"""
Deploy: Global Member ID fix
Only uploads the 2 modified files:
  - process.php (member_id for upload filenames + data.json)
  - admin/export_members.php (member_id column in Excel export)
"""
import ftplib
import os

FTP_HOST = "145.223.86.39"
FTP_USER = "u662618977"
FTP_PASS = "Mm@123456789"
REMOTE_DIR = "domains/yellowgreen-quail-410393.hostingersite.com/public_html"

FILES = [
    ("process.php", "process.php"),
    ("admin/export_members.php", "admin/export_members.php"),
]

def deploy():
    print(f"Connecting to FTP {FTP_HOST}...")
    ftp = ftplib.FTP(FTP_HOST, timeout=30)
    ftp.login(FTP_USER, FTP_PASS)
    print("Login successful.")
    ftp.cwd(REMOTE_DIR)
    print(f"Changed directory to: {REMOTE_DIR}")

    for local, remote in FILES:
        print(f"Uploading {local} -> {remote}...")
        with open(local, "rb") as f:
            ftp.storbinary(f"STOR {remote}", f)

    ftp.quit()
    print(f"\nSuccess! {len(FILES)}/{len(FILES)} files uploaded.")

if __name__ == "__main__":
    deploy()
