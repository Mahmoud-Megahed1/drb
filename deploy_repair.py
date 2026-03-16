import ftplib
import urllib.request
import ssl
import os

# FTP Credentials
host = '145.223.86.39'
user = 'u670530239'
passwd = 'u?j?4F9wX/#r45R@'
remote_base_path = 'domains/yellowgreen-quail-410393.hostingersite.com/public_html'

# Upload the repair script
filename = 'repair_data.php'

if not os.path.exists(filename):
    print(f"ERROR: {filename} not found in current directory!")
    exit(1)

print(f'Connecting to FTP {host}...')
try:
    ftp = ftplib.FTP(host)
    ftp.login(user, passwd)
    ftp.cwd(remote_base_path)
    
    print(f'Uploading {filename}...')
    with open(filename, 'rb') as f:
        ftp.storbinary(f'STOR {filename}', f)
    ftp.quit()
    print('Upload successful!')
    
    # Run the repair script on the server
    print('\nRunning repair script on server...')
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    
    url = f'https://yellowgreen-quail-410393.hostingersite.com/{filename}'
    req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
    
    try:
        with urllib.request.urlopen(req, context=ctx, timeout=120) as response:
            result = response.read().decode('utf-8')
            print(result)
    except Exception as e:
        print(f"HTTP Error: {e}")
        if hasattr(e, 'read'):
            print(e.read().decode('utf-8'))
    
    # Delete repair script from server after running
    print('\nCleaning up: Deleting repair script from server...')
    ftp2 = ftplib.FTP(host)
    ftp2.login(user, passwd)
    ftp2.cwd(remote_base_path)
    try:
        ftp2.delete(filename)
        print(f'Deleted {filename} from server.')
    except:
        print(f'WARNING: Could not delete {filename} - please delete manually!')
    ftp2.quit()
    
except Exception as e:
    print(f"\nError: {e}")
