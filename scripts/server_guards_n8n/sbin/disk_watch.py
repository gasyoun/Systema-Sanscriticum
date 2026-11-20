#!/usr/bin/env python3
# disk-watch: TG alert when / usage crosses threshold.
# Episode semantics (MG 15-11-2026): the FIRST crossing of a threshold episode
# always alerts; the 6h cooldown only suppresses REPEAT alerts of the SAME
# episode. Dropping back below threshold resets the episode. (Incident 15-11:
# "alert suppressed (cooldown)" ate the warning half an hour before ENOSPC.)
import subprocess,urllib.request,urllib.parse,os,sys,time
THRESHOLD=90
STATE="/var/lib/disk-watch.state"
COOLDOWN=6*3600
def envf(path):
    env={}
    for line in open(path):
        line=line.strip()
        if not line or line.startswith("#") or "=" not in line: continue
        k,v=line.split("=",1); env[k.strip()]=v.strip().strip("\"").strip("'")
    return env
out=subprocess.run(["df","-h","/"],capture_output=True,text=True).stdout.splitlines()
used_pct=int(out[1].split()[4].rstrip("%"))
avail=out[1].split()[3]
free_gb=avail if avail.endswith("G") else ("%.1fG"%(float(avail[:-1])/1024) if avail.endswith("M") else avail)  # H8353: int("855M") crashed the alert 17:22Z 15-11
ts=int(time.time())
if used_pct<THRESHOLD:
    if os.path.exists(STATE) and open(STATE).read().split()[:1]!=["ok"]:
        open(STATE,"w").write("ok %d"%ts)
    print("ok: %d%% used, %s free"%(used_pct,free_gb)); sys.exit(0)
alerting=False; last=0
if os.path.exists(STATE):
    parts=open(STATE).read().split()
    if parts and parts[0]=="alerting":
        alerting=True
        if len(parts)>1: last=int(parts[1] or 0)
if alerting and ts-last<COOLDOWN:
    print("repeat alert suppressed (cooldown, same episode)"); sys.exit(0)
env=envf("/etc/systema-backup/alert.env")
tok=env.get("TELEGRAM_BOT_TOKEN"); chat=env.get("TELEGRAM_CHAT_ID")
label="first crossing" if not alerting else "repeat reminder"
msg="[disk-watch %s] / on 193.232.229.91: %d%% used, %s free (threshold %d%%) — освободите место или решите retention (GTD @DECIDE)"%(label,used_pct,free_gb,THRESHOLD)
body=urllib.parse.urlencode({"chat_id":chat,"text":msg}).encode()
try:
    # URL is a fixed https literal; the only dynamic part is the bot token from
    # /etc/systema-backup/alert.env (server-side secret, not user input).
    # Scoped SAST suppression per .github/workflows/semgrep.yml header (H8353).
    urllib.request.urlopen(urllib.request.Request("https://api.telegram.org/bot%s/sendMessage"%tok,data=body),timeout=15) # nosemgrep: python.lang.security.audit.dynamic-urllib-use-detected
    print("TG sent direct (%s)"%label)
except Exception as e:
    curl=["curl","-s","-m","20","-x","socks5h://127.0.0.1:1080","-d","chat_id="+str(chat),"--data-urlencode","text="+msg,"https://api.telegram.org/bot%s/sendMessage"%tok]
    r=subprocess.run(curl,capture_output=True,text=True)
    print("TG sent via socks (%s):"%label,r.stdout[:80])
open(STATE,"w").write("alerting %d"%ts)
