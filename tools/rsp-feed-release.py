#!/usr/bin/env python3
"""One fixed RSP Social Feed 0.6.8→0.6.9 release. No generic installer or uninstall.

Client: package/check/apply/resume. Server execution requires the exact pushed
source bundle in a private fixed directory; tests import the core with a fake host.
"""
import base64, contextlib, ctypes, datetime, fcntl, hashlib, io, json, os
import pathlib, pwd, re, socket, stat, subprocess, sys, tempfile, time, uuid, zipfile
import urllib.request

P = pathlib.Path
HOST = 'root@207.246.65.209'
MACHINE_SHA = '00a74ba281223f527e6f0047b9670c18d6d3c4b8b9f48d540ec2b9903ad6c033'
HOSTNAME = 'rsp-bench172-amd12-20261103-0912'
WPCLI_SHA = 'ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c'
BASE = P('/root/shootcal-social-feed-release-20261109')
LIVE = P('/srv/blog/site/wp-content/plugins/shootcal-social-feed')
CACHE = P('/srv/blog/wp-content/uploads/shootcal-social-feed')
LOCK = P('/run/lock/shootcal-deploy-shared.lock')
FPM_ROOT = P('/etc/shootcal-ownerwp')
OLD = '82fad4e0b10dd43b09dd859b46e5a27b266a829d'
NEW = '97644184d1627f98abf5f95cd8422a74aed3b7eb'
SLUG = 'shootcal-social-feed'
FILE_LIMIT = 4 * 1024 * 1024
TREE_LIMIT = 8 * 1024 * 1024
PACKET_LIMIT = 16 * 1024 * 1024

class Refusal(RuntimeError): pass
def need(ok, label):
    if not ok: raise Refusal(label)
def sha(raw): return hashlib.sha256(raw).hexdigest()
def encoded(value): return (json.dumps(value,sort_keys=True,separators=(',',':'))+'\n').encode()
def syncdir(path):
    fd=os.open(path,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    try: os.fsync(fd)
    finally: os.close(fd)
def private_dir(path):
    s=path.lstat()
    need(stat.S_ISDIR(s.st_mode) and s.st_uid==os.getuid() and s.st_gid==os.getgid() and stat.S_IMODE(s.st_mode)==0o700,'private_directory_metadata')
def regular(path, limit=FILE_LIMIT, owner=None, mode=None):
    s=path.lstat(); need(stat.S_ISREG(s.st_mode) and s.st_nlink==1 and s.st_size<=limit,'regular_file_metadata')
    if owner is not None:
        need((s.st_uid,s.st_gid)==owner if isinstance(owner,tuple) else s.st_uid==owner,'file_owner')
    if mode is not None: need(stat.S_IMODE(s.st_mode)==mode,'file_mode')
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW)
    try:
        actual=os.fstat(fd);need((actual.st_dev,actual.st_ino)==(s.st_dev,s.st_ino),'file_identity_changed')
        with os.fdopen(fd,'rb',closefd=False) as f: raw=f.read(limit+1)
    finally: os.close(fd)
    need(len(raw)<=limit and len(raw)==s.st_size,'file_size_changed');return raw
def atomic(path, raw, mode=0o600):
    """Only a private journal file; never a production path."""
    remnants=list(path.parent.glob('.'+path.name+'.*'));need(len(remnants)<8,'private_temporary_bound')
    temp=path.with_name('.'+path.name+'.'+uuid.uuid4().hex)
    fd=os.open(temp,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,mode)
    try:
        with os.fdopen(fd,'wb',closefd=False) as f:f.write(raw);f.flush();os.fsync(fd)
    finally:os.close(fd)
    os.replace(temp,path);syncdir(path.parent)
def safe_relative(name):
    return isinstance(name,str) and not name.startswith('/') and '\\' not in name and all(x not in ('','.','..') for x in name.split('/'))
def tree(path, expected, uid, device=None, gid=None):
    """Exact files AND directories, without following symlinks or hard links."""
    gid=uid if gid is None else gid
    directories={'.'}
    for name in expected:
        need(safe_relative(name),'contract_path')
        directories.update(str(p) for p in P(name).parents if str(p)!='.')
    s=path.lstat();need(stat.S_ISDIR(s.st_mode) and s.st_uid==uid and s.st_gid==gid and stat.S_IMODE(s.st_mode)==0o755,'tree_root_metadata')
    if device is not None:need(s.st_dev==device,'tree_device')
    found={};seen_dirs=set();total=0
    for root, dirs, names in os.walk(path,followlinks=False,onerror=lambda e: (_ for _ in ()).throw(e)):
        rel=str(P(root).relative_to(path));seen_dirs.add(rel)
        d=P(root).lstat();need(stat.S_ISDIR(d.st_mode) and d.st_uid==uid and d.st_gid==gid and stat.S_IMODE(d.st_mode)==0o755 and d.st_dev==s.st_dev,'tree_directory_metadata')
        need(rel in directories,'foreign_directory')
        for name in dirs:need(not (P(root)/name).is_symlink(),'tree_symlink')
        for name in names:
            p=P(root)/name;key=str(p.relative_to(path));need(key in expected,'foreign_file')
            raw=regular(p,owner=(uid,gid),mode=0o644);total+=len(raw);need(total<=TREE_LIMIT,'tree_size')
            need(p.lstat().st_dev==s.st_dev,'tree_file_device');found[key]=sha(raw)
    need(seen_dirs==directories and found==expected,'tree_hash_drift')
    return sha(encoded(found))
def exchange(a,b):
    libc=ctypes.CDLL(None,use_errno=True)
    if sys.platform=='linux':result=libc.renameat2(-100,os.fsencode(a),-100,os.fsencode(b),2)
    elif sys.platform=='darwin':result=libc.renamex_np(os.fsencode(a),os.fsencode(b),2)
    else:raise Refusal('atomic_exchange_unavailable')
    if result:raise OSError(ctypes.get_errno(),'atomic_exchange_failed')
    syncdir(a.parent);syncdir(b.parent)
def checked(args, raw=None, timeout=30):
    r=subprocess.run(args,input=raw,capture_output=True,timeout=timeout)
    need(r.returncode==0,'command_failed');return r.stdout

def verify_fpm_config(root,owner=0):
    """Admit only the verified FPM root→two-pool include graph, never nginx."""
    pool=root/'pool.d'
    for directory in (root,pool):
        s=directory.lstat()
        need(directory.resolve()==directory and stat.S_ISDIR(s.st_mode) and s.st_uid==owner and not s.st_mode&0o022,'fpm_directory_metadata')
    with os.scandir(pool) as entries:
        names={entry.name for entry in entries if entry.name.endswith('.conf')}
    need(names=={'rsp.conf','axis.conf'},'fpm_pool_inventory')
    paths=[root/'php-fpm.conf',pool/'axis.conf',pool/'rsp.conf']
    for path in paths:
        raw=regular(path,owner=owner).decode();includes=[]
        for line in raw.splitlines():
            text=line.strip()
            if not text or text.startswith((';','#')):continue
            if re.match(r'include\b',text,re.I):
                match=re.fullmatch(r'include\s*=\s*(\S+)',text,re.I)
                need(match is not None,'fpm_include_graph');includes.append(match.group(1))
            need(not re.match(r'''php_(?:admin_)?(?:value|flag)\s*\[\s*["']?\s*opcache\.(?:validate_timestamps|revalidate_freq)\s*["']?\s*\]''',text,re.I),'opcache_pool_override')
        expected=[str(pool/'*.conf')] if path==paths[0] else []
        need(includes==expected,'fpm_include_graph')
    return paths

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs):raise Refusal('backup_rpc_redirect')

def indexed_backup(plan_summary,now):
    """Read-only manager RPC; never submits a backup or a restore."""
    config=json.loads(regular(P('/etc/shootcal-amd-backup/status.json'),owner=0,mode=0o600))
    servers=[s for s in config['servers'] if s.get('key')=='consolidated']
    need(len(servers)==1 and servers[0]['url']=='http://127.0.0.1:19899','backup_rpc_origin')
    server=servers[0];auth=base64.b64encode((server['username']+':'+server['password']).encode()).decode()
    request=urllib.request.Request('http://127.0.0.1:19899/v1.Backrest/GetOperations',
        encoded({'selector':{'planId':'amd-hourly-databases-config'},'lastN':'40'}),
        {'Content-Type':'application/json','Authorization':'Basic '+auth})
    opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect())
    with opener.open(request,timeout=10) as response:
        raw=response.read(4*1024*1024+1);need(response.status==200 and len(raw)<=4*1024*1024,'backup_rpc_bound')
    rows=json.loads(raw)['operations']
    backups=sorted((r for r in rows if r.get('planId')=='amd-hourly-databases-config' and 'operationBackup' in r and r.get('status')!='STATUS_PENDING'),key=lambda r:int(r.get('unixTimeStartMs','0')),reverse=True)
    need(backups,'backup_operation_missing');row=backups[0];data=row['operationBackup'];snapshot=row.get('snapshotId','')
    need(row.get('status')=='STATUS_SUCCESS' and not data.get('errors') and not data.get('dryRun') and re.fullmatch('[a-f0-9]{64}',snapshot),'backup_operation_not_success')
    end=int(row.get('unixTimeEndMs','0'))/1000;start=int(row.get('unixTimeStartMs','0'))/1000
    need(0<start<=end<=now and now-end<=4500 and snapshot.startswith(plan_summary['last_snapshot']),'backup_operation_freshness')
    indexes=[r for r in rows if r.get('status')=='STATUS_SUCCESS' and 'operationIndexSnapshot' in r and (r.get('snapshotId') or r['operationIndexSnapshot'].get('snapshot',{}).get('id'))==snapshot]
    need(len(indexes)==1,'backup_snapshot_index_missing')
    return {'snapshotSha256':sha(snapshot.encode()),'operation':str(row['id']),'indexOperation':str(indexes[0]['id'])}

def validate_contract(c):
    need(c.get('baselineCommit')==OLD and c.get('runtimeCommit')==NEW and c.get('slug')==SLUG,'runtime_source_pin')
    need(c.get('baselineVersion')=='0.6.8' and c.get('runtimeVersion')=='0.6.9','runtime_version_pin')
    need(set(c['before'])==set(c['after']) and len(c['after'])==29,'runtime_inventory')
    for names in (c['before'],c['after']):
        need(all(safe_relative(k) and re.fullmatch('[a-f0-9]{64}',v) for k,v in names.items()),'runtime_hash_contract')
        need(not any(k.startswith(('tools/','tests/','.')) for k in names),'private_file_in_runtime')

def unpack(raw, destination, expected, uid, gid=None):
    """Prevalidate all entries before creating the new tree."""
    gid=uid if gid is None else gid
    with zipfile.ZipFile(io.BytesIO(raw)) as z:
        infos=z.infolist();need(len(infos)==29,'zip_inventory')
        bodies={};total=0
        for item in infos:
            need(item.filename.startswith(SLUG+'/'),'zip_slug')
            name=item.filename[len(SLUG)+1:]
            need(safe_relative(name) and name in expected and name not in bodies,'zip_path')
            need(stat.S_IFMT(item.external_attr>>16)==stat.S_IFREG and not item.flag_bits&1 and item.file_size<=FILE_LIMIT,'zip_type_or_size')
            total+=item.file_size;need(total<=TREE_LIMIT,'zip_total_size')
            body=z.read(item);need(sha(body)==expected[name],'zip_hash');bodies[name]=body
        need(set(bodies)==set(expected),'zip_missing')
    need(not os.path.lexists(destination),'candidate_already_exists')
    destination.mkdir(mode=0o755);os.chown(destination,uid,gid)
    for name,body in sorted(bodies.items()):
        target=destination/name
        for parent in reversed(target.parent.relative_to(destination).parents):
            if str(parent)!='.':(destination/parent).mkdir(mode=0o755,exist_ok=True)
        target.parent.mkdir(mode=0o755,parents=True,exist_ok=True)
        fd=os.open(target,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o644)
        try:
            os.fchown(fd,uid,gid);os.fchmod(fd,0o644)
            with os.fdopen(fd,'wb',closefd=False) as f:f.write(body);f.flush();os.fsync(fd)
        finally:os.close(fd)
    for root,dirs,files in os.walk(destination,topdown=False):
        os.chown(root,uid,gid);os.chmod(root,0o755);syncdir(root)
    syncdir(destination.parent);tree(destination,expected,uid,gid=gid)

class Engine:
    def __init__(self,host,contract,manifest):
        validate_contract(contract);self.h=host;self.c=contract;self.m=manifest
        need(re.fullmatch('[a-f0-9]{40}',manifest.get('sourceHead','')),'source_head')
        self.journal=host.run/'journal.json';self.other=host.run/'exchange-tree'
    def boundary(self,name):self.h.boundary(name)
    def save(self,state):atomic(self.journal,encoded(state));self.boundary('durable_'+state['phase'])
    def identities(self):
        return {'sourceHead':self.m['sourceHead'],'packageSha256':self.m['zipSha256'],
                'operatorSha256':self.m['files']['rsp-feed-release.py'],'contractSha256':self.m['files']['rsp-feed-release-contract.json']}
    def state(self):
        if not os.path.lexists(self.journal):return None
        value=json.loads(regular(self.journal,owner=os.getuid(),mode=0o600))
        need(all(value.get(k)==v for k,v in self.identities().items()),'journal_source_authority')
        need(value.get('phase') in ('building','prepared','exchanged','complete'),'journal_phase');return value
    def verify_pair(self,new_live):
        tree(self.h.live,self.c['after' if new_live else 'before'],self.h.uid,self.h.device,self.h.gid)
        tree(self.other,self.c['before' if new_live else 'after'],self.h.uid,self.h.device,self.h.gid)
    def preservation(self,state):
        raw,summary=self.h.options();need(sha(raw)==state['optionsSha256'] and summary==state['optionsSummary'],'options_changed')
        need(self.h.cache()==state['cache'],'cache_changed')
        return raw
    def execute(self,action):
        need(action in ('check','apply','resume'),'action')
        self.h.deadline=time.monotonic()+120
        with self.h.lock():
            self.h.identity();private_dir(self.h.run)
            need(self.h.run.lstat().st_dev==self.h.live.parent.lstat().st_dev,'staging_filesystem')
            state=self.state()
            if state and state['phase']=='complete':
                self.verify_pair(True);self.h.verify_seal(state)
                return {'state':'complete','preservation':'verified_at_commit',**self.identities(),**state['optionsSummary']}
            self.h.preflight()
            if state and state['phase']!='building':
                # Infer the exchange result from exact physical trees. A crash may
                # occur after rename but before the following journal write.
                try:self.verify_pair(True);new_live=True
                except (Refusal,OSError):self.verify_pair(False);new_live=False
                need(state['phase']=='prepared' or new_live,'journal_tree_state')
                self.preservation(state);self.h.verify_seal(state)
            else:
                need(action!='resume' or state is not None,'no_journal_to_resume')
                tree(self.h.live,self.c['before'],self.h.uid,self.h.device,self.h.gid)
                raw,summary=self.h.options();cache=self.h.cache()
                candidate_present=os.path.lexists(self.other)
                if candidate_present:tree(self.other,self.c['after'],self.h.uid,self.h.device,self.h.gid)
                else:
                    attempts=list(self.h.run.glob('.candidate-*'));need(len(attempts)<8,'candidate_attempt_bound')
                    for attempt in attempts:
                        st=attempt.lstat();need(re.fullmatch(r'\.candidate-[a-f0-9]{32}',attempt.name) and stat.S_ISDIR(st.st_mode) and st.st_uid in (os.getuid(),self.h.uid),'candidate_attempt_metadata')
                if action=='check':return {'state':state['phase'] if state else 'eligible',**self.identities(),**summary,'cacheFiles':cache['files']}
                if state is None:
                    state={**self.identities(),'phase':'building'};self.save(state)
                if not candidate_present:
                    stage=self.h.run/('.candidate-'+uuid.uuid4().hex)
                    unpack(self.h.package(),stage,self.c['after'],self.h.uid,self.h.gid)
                    self.boundary('candidate_files_durable')
                    need(not os.path.lexists(self.other),'candidate_promotion_conflict')
                    stage.rename(self.other);syncdir(self.h.run)
                self.boundary('candidate_durable')
                seal=self.h.seal(raw);self.boundary('capture_durable')
                state={**self.identities(),'phase':'prepared','optionsSha256':sha(raw),'optionsSummary':summary,'cache':cache,'admission':self.h.admission,**seal}
                self.save(state);new_live=False
            if action=='check':return {'state':state['phase'],**self.identities(),**state['optionsSummary']}
            if not new_live:
                self.h.preflight();self.preservation(state);self.verify_pair(False)
                self.h.window();self.boundary('before_exchange');exchange(self.other,self.h.live);self.boundary('after_exchange')
                self.verify_pair(True);state['phase']='exchanged';self.save(state)
            elif state['phase']=='prepared':state['phase']='exchanged';self.save(state)
            self.h.preflight();self.preservation(state);self.verify_pair(True)
            self.h.purge();self.boundary('after_purge')
            self.preservation(state);self.verify_pair(True)
            state['phase']='complete';self.save(state)
            return {'state':'complete','preservation':'verified_at_commit',**self.identities(),**state['optionsSummary'],'cacheFiles':state['cache']['files']}

class Host:
    private_uid=0
    def __init__(self,run,manifest):
        self.run=run;self.manifest=manifest;self.live=LIVE
        account=pwd.getpwnam('rspblog');need((account.pw_uid,account.pw_gid)==(995,980),'rsp_identity')
        self.uid=account.pw_uid;self.gid=account.pw_gid;self.device=64770
    def command(self,args,raw=None,timeout=30):
        remaining=self.deadline-time.monotonic();need(remaining>0,'operation_deadline')
        return checked(args,raw,timeout=min(timeout,remaining))
    def boundary(self,name):pass
    @contextlib.contextmanager
    def lock(self):
        fd=os.open(LOCK,os.O_RDWR|os.O_NOFOLLOW)
        try:
            s=os.fstat(fd);need(stat.S_ISREG(s.st_mode) and s.st_uid==0 and s.st_nlink==1 and not s.st_mode&0o022,'shared_lock_metadata')
            fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB);yield
        finally:os.close(fd)
    def identity(self):
        need(sys.platform=='linux' and os.geteuid()==0,'amd_root_only')
        need(socket.gethostname()==HOSTNAME and sha(regular(P('/etc/machine-id'),owner=(0,0),mode=0o444))==MACHINE_SHA,'amd_machine_identity')
        need(sha(regular(P('/usr/local/lib/shootcal-smallwp/wp-cli.phar'),8*1024*1024,owner=(0,0),mode=0o755))==WPCLI_SHA,'wpcli_source_drift')
        interfaces=json.loads(self.command(['ip','-j','address','show']))
        need(any(a.get('local')=='207.246.65.209' for i in interfaces for a in i.get('addr_info',[])),'amd_address')
        need(LIVE.parent.resolve()==LIVE.parent and LIVE.parent.lstat().st_dev==64770,'fixed_plugin_parent')
    def window(self,start=True):
        now=time.time();utc=datetime.datetime.fromtimestamp(now,datetime.timezone.utc)
        need(utc.minute>=48 or utc.minute<=(18 if start else 24),'protected_backup_window')
        if not start:need(utc.minute!=24 or utc.second<40,'purge_cutoff_lead')
        need(not (utc.hour==4 and 5<=utc.minute<=18) and not (utc.hour==5 and utc.minute>=55) and not (utc.hour==6 and utc.minute<=10),'daily_backup_lead_window')
    def preflight(self):
        self.identity();self.window();now=time.time()
        need(not os.path.lexists('/root/owner-ops-20261105/rsp-code/HOLD'),'rsp_hold')
        status=json.loads(regular(P('/var/lib/shootcal/backup-status/backrest.json'),owner=0))
        need(0<=now-status['generated_at']<=390,'backup_status_stale')
        servers=[s for s in status['servers'] if s.get('key')=='consolidated'];need(len(servers)==1 and servers[0]['reachable'],'backup_server')
        plans={p['id']:p for p in servers[0]['plans']}
        for name,age in [('amd-hourly-databases-config',4500),('amd-daily-owner-media',100800),('amd-daily-shootcal-files',100800)]:
            p=plans[name];need(p['last_status']=='success' and p['failures_24h']==0 and 0<=now-p['last_success_at']<=age and re.fullmatch('[a-f0-9]{8}',p['last_snapshot']),'backup_success_required')
        props=dict(line.split('=',1) for line in self.command(['systemctl','show','shootcal-amd-backrest.service','-p','ActiveState','-p','MainPID']).decode().splitlines())
        need(props.get('ActiveState')=='active' and int(props.get('MainPID','0'))>1,'backup_manager_state')
        children=subprocess.run(['pgrep','-P',props['MainPID']],capture_output=True,timeout=min(5,max(0.1,self.deadline-time.monotonic())))
        need(children.returncode==1,'backup_manager_busy')
        proof=indexed_backup(plans['amd-hourly-databases-config'],now)
        self.admission={'backupStatusSha256':sha(encoded(status)),'managerPid':props['MainPID'],**proof}
        # CLI loads the protected owner PHP ini. Follow the actual FPM include
        # closure and refuse unknown includes/pools and value/flag overrides.
        values=json.loads(self.command(['php8.5','-c','/etc/shootcal-ownerwp/php.ini','-r','echo json_encode([ini_get("opcache.validate_timestamps"),ini_get("opcache.revalidate_freq")]);']))
        need(values==['1','2'],'opcache_timestamp_settings')
        verify_fpm_config(FPM_ROOT)
        self.window() # slow admission reads cannot preserve an expired clock decision
    def package(self):
        raw=regular(self.run/'candidate.zip',TREE_LIMIT,owner=self.private_uid,mode=0o600);need(sha(raw)==self.manifest['zipSha256'],'package_hash');return raw
    def options(self):
        args=['runuser','-u','rspblog','--','php8.5','-c','/etc/shootcal-ownerwp/php.ini','-d','memory_limit=512M','-d','sendmail_path=/bin/false','/usr/local/lib/shootcal-smallwp/wp-cli.phar','--path=/srv/blog/site','--url=https://www.ryansmithphotography.com/','--skip-plugins','--skip-themes','--skip-packages','db','query']
        sql="SELECT option_id,HEX(option_name),HEX(option_value),HEX(autoload) FROM wp_options WHERE LEFT(option_name,CHAR_LENGTH('shootcal_instagram_feed'))='shootcal_instagram_feed' OR option_name='active_plugins' ORDER BY BINARY option_name;"
        for field,expected in [('DB_NAME','rsp_blog'),('table_prefix','wp_')]:
            query=args[:-2]+['config','get',field]+(['--type=variable'] if field=='table_prefix' else [])
            need(self.command(query).decode().strip()==expected,'wordpress_database_identity')
        raw=self.command(args+[sql,'--skip-column-names','--batch','--raw']);need(0<len(raw)<=16*1024*1024,'option_capture_size')
        rows=raw.splitlines()
        # WP-CLI appends one formatter newline after MySQL's final record LF.
        # Validate records without that one empty terminator; retain raw bytes.
        if raw.endswith(b'\n\n') and rows[-1]==b'':rows.pop()
        need(1<len(rows)<=128,'option_capture_count');active=None
        for row in rows:
            fields=row.split(b'\t');need(len(fields)==4 and fields[0].isdigit(),'option_capture_format')
            for f in fields[1:]:need(re.fullmatch(b'(?:[A-Fa-f0-9]{2})*',f) is not None,'option_hex_format')
            name=bytes.fromhex(fields[1].decode());need(name==b'active_plugins' or name.startswith(b'shootcal_instagram_feed'),'option_scope')
            if name==b'active_plugins':need(active is None,'duplicate_active_plugins');active=bytes.fromhex(fields[2].decode())
        need(active is not None,'active_plugins_missing')
        code='$a=unserialize(stream_get_contents(STDIN),["allowed_classes"=>false]);if(!is_array($a)||count(array_filter($a,"is_string"))!==count($a)){exit(2);}echo json_encode(["activePluginCount"=>count($a),"feedActive"=>in_array("shootcal-social-feed/shootcal-instagram-feed.php",$a,true)]);'
        info=json.loads(self.command(['php8.5','-n','-r',code],active));need(info['feedActive'],'feed_not_active')
        return raw,{'optionRows':len(rows)-1,'optionsSha256':sha(raw),**info}
    def cache(self):
        need(CACHE.resolve()==CACHE,'cache_realpath')
        if not os.path.lexists(CACHE):return {'files':0,'bytes':0,'sha256':sha(b'absent')}
        s=CACHE.lstat();need(stat.S_ISDIR(s.st_mode) and s.st_uid==self.uid,'cache_directory')
        found={};total=0
        with os.scandir(CACHE) as entries:
            for e in entries:
                need(len(found)<512 and re.fullmatch('[a-f0-9]{64}-(320|640|1280)\\.(jpg|jpeg|webp|avif)',e.name),'cache_file_scope')
                raw=regular(P(e.path),8*1024*1024,owner=self.uid);total+=len(raw);need(total<=64*1024*1024,'cache_byte_bound');found[e.name]=sha(raw)
        return {'files':len(found),'bytes':total,'sha256':sha(encoded(found))}
    def seal(self,raw):
        key=self.run/'options.key';cipher=self.run/'options.enc'
        if os.path.lexists(key):regular(key,owner=self.private_uid,mode=0o600)
        else:atomic(key,os.urandom(48).hex().encode()+b'\n')
        encrypted=self.command(['openssl','enc','-aes-256-cbc','-pbkdf2','-iter','200000','-salt','-pass','file:'+str(key)],raw)
        # Orphan capture after a crash is replaceable only before journal intent;
        # no plaintext option value is ever written or returned to the client.
        atomic(cipher,encrypted);return {'encryptedOptionsSha256':sha(encrypted),'keySha256':sha(regular(key,owner=self.private_uid,mode=0o600))}
    def verify_seal(self,state):
        key=regular(self.run/'options.key',owner=self.private_uid,mode=0o600);cipher=regular(self.run/'options.enc',32*1024*1024,owner=self.private_uid,mode=0o600)
        need(sha(key)==state['keySha256'] and sha(cipher)==state['encryptedOptionsSha256'],'encrypted_capture_drift')
        raw=self.command(['openssl','enc','-d','-aes-256-cbc','-pbkdf2','-iter','200000','-pass','file:'+str(self.run/'options.key')],cipher)
        need(sha(raw)==state['optionsSha256'],'encrypted_capture_invalid')
    def purge(self):
        self.window(start=False)
        time.sleep(3) # existing timestamp-based opcache; never reload FPM
        self.window(start=False)
        result=self.command(['curl','--fail','--silent','--show-error','--max-time','15','-o','/dev/null','-w','%{http_code}','-H','X-Purge-Host: www.ryansmithphotography.com','http://127.0.0.1:8090/purge/*'])
        need(result.strip()==b'200','rsp_page_purge_failed')
        # One bounded SCAN cursor traversal; do not use Redis FLUSHDB/FLUSHALL.
        cursor='0';seen=set();count=0;deadline=time.monotonic()+30
        for _ in range(10000):
            need(time.monotonic()<deadline,'redis_purge_deadline')
            response=json.loads(self.command(['redis-cli','-s','/run/redis/redis-server.sock','--json','SCAN',cursor,'MATCH','rsp-plainwp:*','COUNT','200'],timeout=min(5,max(0.1,deadline-time.monotonic()))))
            need(isinstance(response,list) and len(response)==2,'redis_scan_response');cursor=str(response[0]);keys=response[1]
            need(re.fullmatch('[0-9]+',cursor) and isinstance(keys,list),'redis_cursor')
            need(all(isinstance(k,str) and k.startswith('rsp-plainwp:') and '\x00' not in k for k in keys),'foreign_redis_key')
            count+=len(keys);need(count<=100000,'redis_key_bound')
            for offset in range(0,len(keys),200):
                batch=keys[offset:offset+200]
                self.window(start=False)
                if batch:need(re.fullmatch(b'[0-9]+',self.command(['redis-cli','-s','/run/redis/redis-server.sock','--raw','UNLINK',*batch],timeout=min(5,max(0.1,deadline-time.monotonic()))).strip()) is not None,'redis_unlink_failed')
            if cursor=='0':return
            need(cursor not in seen,'redis_cursor_loop');seen.add(cursor)
        raise Refusal('redis_scan_bound')

def make_package(repo,c):
    validate_contract(c);raw=io.BytesIO()
    with zipfile.ZipFile(raw,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        for name,digest in sorted(c['after'].items()):
            body=checked(['git','-C',str(repo),'show','HEAD:'+name]);need(sha(body)==digest,'source_runtime_changed')
            info=zipfile.ZipInfo(SLUG+'/'+name,(1980,1,1,0,0,0));info.create_system=3;info.external_attr=(stat.S_IFREG|0o644)<<16;info.compress_type=zipfile.ZIP_DEFLATED;z.writestr(info,body,compresslevel=9)
    return raw.getvalue()

# This small staging program never touches WordPress or its journal. Existing
# source-pinned files are compared, not overwritten. A failed upload is resumable.
STAGER='''import base64,fcntl,hashlib,json,os,pathlib,re,socket,stat,sys,uuid
P=pathlib.Path
def need(v):
 if not v:raise SystemExit("staging_refused")
need(os.geteuid()==0 and re.fullmatch("[a-f0-9]{40}",sys.argv[1]))
need(socket.gethostname()=="rsp-bench172-amd12-20261103-0912" and hashlib.sha256(P("/etc/machine-id").read_bytes()).hexdigest()=="00a74ba281223f527e6f0047b9670c18d6d3c4b8b9f48d540ec2b9903ad6c033")
need(P("/root").lstat().st_dev==64770)
packet=json.loads(sys.stdin.buffer.read(16777217));need(set(packet)=={"candidate.zip","rsp-feed-release.py","rsp-feed-release-contract.json","manifest.json"})
base=P("/root/shootcal-social-feed-release-20261109")
for p in [base,base/sys.argv[1]]:
 if not os.path.lexists(p):p.mkdir(mode=0o700);f=os.open(p.parent,os.O_RDONLY|os.O_DIRECTORY);os.fsync(f);os.close(f)
 s=p.lstat();need(stat.S_ISDIR(s.st_mode) and s.st_uid==0 and stat.S_IMODE(s.st_mode)==0o700)
run=base/sys.argv[1]
lock=os.open(run/".upload.lock",os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW,0o600);ls=os.fstat(lock);need(stat.S_ISREG(ls.st_mode) and ls.st_uid==0 and ls.st_nlink==1 and stat.S_IMODE(ls.st_mode)==0o600);fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
for name,value in packet.items():
 raw=base64.b64decode(value,validate=True);need(len(raw)<=8388608)
 p=run/name
 if os.path.lexists(p):
  s=p.lstat();need(stat.S_ISREG(s.st_mode) and s.st_uid==0 and s.st_nlink==1 and stat.S_IMODE(s.st_mode)==0o600 and p.read_bytes()==raw)
  continue
 need(len(list(run.glob(".upload-*")))<16)
 t=run/(".upload-"+uuid.uuid4().hex);fd=os.open(t,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
 with os.fdopen(fd,"wb")as f:f.write(raw);f.flush();os.fsync(f.fileno())
 os.replace(t,p);d=os.open(run,os.O_RDONLY|os.O_DIRECTORY);os.fsync(d);os.close(d)
print("private source bundle staged")
'''

def client(action):
    need(action in ('package','check','apply','resume'),'client_action')
    repo=P(__file__).resolve().parent.parent
    need(not checked(['git','-C',str(repo),'status','--porcelain','--untracked-files=all']).strip(),'source_tree_dirty')
    head=checked(['git','-C',str(repo),'rev-parse','HEAD']).decode().strip();need(re.fullmatch('[a-f0-9]{40}',head),'source_head')
    checked(['git','-C',str(repo),'merge-base','--is-ancestor',NEW,head])
    branch=checked(['git','-C',str(repo),'symbolic-ref','--short','HEAD']).decode().strip();need(re.fullmatch('[A-Za-z0-9_./-]+',branch),'source_branch')
    remote=checked(['git','-C',str(repo),'ls-remote','--exit-code','origin','refs/heads/'+branch]).decode().split()
    need(len(remote)==2 and remote[0]==head,'source_head_not_pushed')
    contract=checked(['git','-C',str(repo),'show','HEAD:tools/rsp-feed-release-contract.json']);c=json.loads(contract)
    helper=checked(['git','-C',str(repo),'show','HEAD:tools/rsp-feed-release.py']);package=make_package(repo,c)
    files={'candidate.zip':package,'rsp-feed-release.py':helper,'rsp-feed-release-contract.json':contract}
    manifest={'sourceHead':head,'runtimeCommit':NEW,'zipSha256':sha(package),'files':{k:sha(v) for k,v in files.items()}}
    files['manifest.json']=encoded(manifest)
    if action=='package':
        directory=P(tempfile.mkdtemp(prefix='rsp-social-feed-release-'));os.chmod(directory,0o700)
        for name,raw in files.items():atomic(directory/name,raw)
        print(json.dumps({'sourceHead':head,'zipSha256':sha(package),'runtimeFiles':29,'packageDirectory':str(directory)}));return
    packet=encoded({k:base64.b64encode(v).decode() for k,v in files.items()});need(len(packet)<=PACKET_LIMIT,'packet_size')
    checked(['ssh','-o','BatchMode=yes','-o','ConnectTimeout=10',HOST,'python3','-I','-B','-c',__import__('shlex').quote(STAGER),head],packet)
    # Only fixed root and validated hexadecimal source pin enter the remote command.
    result=checked(['ssh','-o','BatchMode=yes','-o','ConnectTimeout=10',HOST,'python3','-I','-B',str(BASE/head/'rsp-feed-release.py'),'--server',action,head],timeout=180)
    print(result.decode(),end='')

def server(action,head):
    need(re.fullmatch('[a-f0-9]{40}',head),'source_head');run=BASE/head
    need(P(__file__).resolve()==run/'rsp-feed-release.py','server_source_path');private_dir(BASE);private_dir(run)
    manifest=json.loads(regular(run/'manifest.json',owner=0,mode=0o600));need(manifest['sourceHead']==head and manifest['runtimeCommit']==NEW,'server_source_pin')
    need(set(manifest['files'])=={'candidate.zip','rsp-feed-release.py','rsp-feed-release-contract.json'},'source_bundle_inventory')
    for name,digest in manifest['files'].items():need(sha(regular(run/name,TREE_LIMIT,owner=0,mode=0o600))==digest,'source_bundle_drift')
    contract=json.loads(regular(run/'rsp-feed-release-contract.json',owner=0,mode=0o600))
    print(json.dumps(Engine(Host(run,manifest),contract,manifest).execute(action),sort_keys=True))

def main():
    os.umask(0o077)
    try:
        if len(sys.argv)==4 and sys.argv[1]=='--server':server(sys.argv[2],sys.argv[3])
        elif len(sys.argv)==2:client(sys.argv[1])
        else:raise Refusal('usage_package_check_apply_resume')
    except Exception as e:
        # Provider/SQL/option/subprocess payloads stay in memory. Never echo a
        # command's stdout/stderr or arbitrary exception text into release logs.
        label=str(e) if isinstance(e,Refusal) else type(e).__name__
        print('RSP_FEED_REFUSED '+label,file=sys.stderr);return 2
    return 0
if __name__=='__main__':sys.exit(main())
