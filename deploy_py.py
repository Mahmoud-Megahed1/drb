import ftplib
import urllib.request
import ssl

host = '145.223.86.39'
user = 'u670530239'
passwd = 'u?j?4F9wX/#r45R@'

print('Connecting to FTP...')
ftp = ftplib.FTP(host)
ftp.login(user, passwd)
ftp.cwd('domains/yellowgreen-quail-410393.hostingersite.com/public_html')

filename = 'fix_missing_registrations.php'
print(f'Uploading {filename}...')
with open(filename, 'rb') as f:
    ftp.storbinary(f'STOR {filename}', f)
ftp.quit()

print('Running script on server...')
ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode = ssl.CERT_NONE

req = urllib.request.Request(f'https://yellowgreen-quail-410393.hostingersite.com/{filename}', headers={'User-Agent': 'Mozilla/5.0'})
try:
    with urllib.request.urlopen(req, context=ctx) as response:
        print(response.read().decode('utf-8'))
except Exception as e:
    print(f"HTTP Error: {e}")
    if hasattr(e, 'read'):
        print(e.read().decode('utf-8'))
