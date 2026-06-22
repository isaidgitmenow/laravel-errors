import urllib.request
try:
    req = urllib.request.Request('https://www.gnu.org/licenses/gpl-3.0.txt', headers={'User-Agent': 'Mozilla/5.0'})
    with urllib.request.urlopen(req) as response:
        gpl = response.read().decode('utf-8')
        with open('gpl.txt', 'w', encoding='utf-8') as f:
            f.write(gpl)
except Exception as e:
    print(e)
