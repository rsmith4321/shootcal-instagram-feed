#!/usr/bin/env python3
"""Disposable local files, real atomic exchange/fsync/AES/process death; no host IO."""
import contextlib, copy, hashlib, importlib.util, io, json, os, pathlib, signal
import shutil, stat, subprocess, sys, tempfile, unittest, zipfile
from types import SimpleNamespace
from unittest import mock

ROOT=pathlib.Path(__file__).resolve().parent.parent
spec=importlib.util.spec_from_file_location('rsp_release',ROOT/'tools/rsp-feed-release.py')
R=importlib.util.module_from_spec(spec);spec.loader.exec_module(R)
CONTRACT=json.loads((ROOT/'tools/rsp-feed-release-contract.json').read_text())

def payloads(pin):
    return {name:subprocess.check_output(['git','-C',str(ROOT),'show',pin+':'+name]) for name in CONTRACT['after']}
BEFORE=payloads(R.OLD);AFTER=payloads(R.NEW)
def package(bodies):
    raw=io.BytesIO()
    with zipfile.ZipFile(raw,'w',compression=zipfile.ZIP_DEFLATED) as z:
        for name,body in sorted(bodies.items()):
            item=zipfile.ZipInfo(R.SLUG+'/'+name);item.create_system=3;item.external_attr=(stat.S_IFREG|0o644)<<16;z.writestr(item,body)
    return raw.getvalue()
OLD_ZIP=package(BEFORE);NEW_ZIP=package(AFTER)
MANIFEST={'sourceHead':'a'*40,'zipSha256':R.sha(NEW_ZIP),'files':{'rsp-feed-release.py':'b'*64,'rsp-feed-release-contract.json':'c'*64,'candidate.zip':R.sha(NEW_ZIP)}}

class FakeHost(R.Host):
    def __init__(self,root,setup=False):
        self.root=root;self.run=root/'run';self.live=root/'plugins'/R.SLUG
        self.uid=os.getuid();self.gid=os.getgid();self.private_uid=self.uid;self.device=root.stat().st_dev
        self.deadline=R.time.monotonic()+120;self.manifest=MANIFEST;self.admission={'synthetic':True};self.kill_at=None;self.hook=None
        if setup:
            self.run.mkdir(mode=0o700);self.live.parent.mkdir()
            R.atomic(self.run/'candidate.zip',NEW_ZIP)
            R.unpack(OLD_ZIP,self.live,CONTRACT['before'],self.uid,self.gid)
            (root/'options-current').write_bytes(b'synthetic-token-canary-options-and-cache\n')
            (root/'cached-rendition').write_bytes(b'owned synthetic media')
            (root/'purge-events').write_text('0')
    def boundary(self,name):
        if self.hook:self.hook(name)
        if self.kill_at==name:os.kill(os.getpid(),signal.SIGKILL)
    @contextlib.contextmanager
    def lock(self):
        fd=os.open(self.root/'lock',os.O_RDWR|os.O_CREAT,0o600)
        try:R.fcntl.flock(fd,R.fcntl.LOCK_EX|R.fcntl.LOCK_NB);yield
        finally:os.close(fd)
    def identity(self):pass
    def window(self,start=True):pass
    def preflight(self):self.admission={'synthetic':True}
    def options(self):
        raw=(self.root/'options-current').read_bytes()
        return raw,{'optionRows':6,'optionsSha256':R.sha(raw),'activePluginCount':19,'feedActive':True}
    def cache(self):
        raw=(self.root/'cached-rendition').read_bytes();return {'files':1,'bytes':len(raw),'sha256':R.sha(raw)}
    def purge(self):
        p=self.root/'purge-events';p.write_text(str(int(p.read_text())+1))
        if (self.root/'purge-fail').exists():raise R.Refusal('synthetic_purge_failed')

class ReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='rsp-feed-local-test-');self.root=pathlib.Path(self.temp.name).resolve()
        self.h=FakeHost(self.root,True)
    def tearDown(self):self.temp.cleanup()
    def engine(self,host=None,manifest=None):return R.Engine(host or self.h,CONTRACT,manifest or MANIFEST)
    def assertions_after(self):
        R.tree(self.h.live,CONTRACT['after'],self.h.uid,self.h.device,self.h.gid)
        R.tree(self.h.run/'exchange-tree',CONTRACT['before'],self.h.uid,self.h.device,self.h.gid)
    def test_private_temporary_bound_preserves_interrupted_capture_evidence(self):
        target=self.h.run/'options.enc'
        for n in range(8):(self.h.run/('.options.enc.'+format(n,'032x'))).write_bytes(b'private interrupted capture')
        with self.assertRaisesRegex(R.Refusal,'private_temporary_bound'):R.atomic(target,b'new private capture')
        self.assertFalse(target.exists());self.assertEqual(len(list(self.h.run.glob('.options.enc.*'))),8)
    def test_fixed_contract_matches_both_git_runtime_trees(self):
        self.assertEqual(len(CONTRACT['before']),29)
        self.assertEqual(CONTRACT['before'],{k:R.sha(v) for k,v in BEFORE.items()})
        self.assertEqual(CONTRACT['after'],{k:R.sha(v) for k,v in AFTER.items()})
    def test_check_is_read_only_for_live_source_options_and_cache(self):
        before=self.h.live.stat().st_ino;result=self.engine().execute('check')
        self.assertEqual(result['state'],'eligible');self.assertEqual(before,self.h.live.stat().st_ino)
        self.assertFalse((self.h.run/'journal.json').exists());self.assertFalse((self.h.run/'exchange-tree').exists())
        self.assertEqual((self.root/'purge-events').read_text(),'0')
    def test_apply_atomic_exchange_preserves_old_tree_and_encrypted_options(self):
        old_inode=self.h.live.stat().st_ino;raw,summary=self.h.options();cache=self.h.cache()
        result=self.engine().execute('apply');self.assertEqual(result['state'],'complete');self.assertions_after()
        self.assertEqual((self.h.run/'exchange-tree').stat().st_ino,old_inode)
        self.assertEqual(self.h.options(),(raw,summary));self.assertEqual(cache,self.h.cache())
        for name in ('options.key','options.enc','journal.json'):
            self.assertEqual(stat.S_IMODE((self.h.run/name).stat().st_mode),0o600)
        self.assertNotIn(b'synthetic-token-canary', (self.h.run/'options.enc').read_bytes())
        self.assertNotIn(b'synthetic-token-canary', (self.h.run/'journal.json').read_bytes())
        self.h.verify_seal(self.engine().state())
    def test_completed_lost_ack_resume_does_not_exchange_or_purge_twice(self):
        first=self.engine().execute('apply');inode=self.h.live.stat().st_ino
        again=self.engine(FakeHost(self.root)).execute('resume')
        self.assertEqual(first['sourceHead'],again['sourceHead']);self.assertEqual(inode,self.h.live.stat().st_ino)
        self.assertEqual((self.root/'purge-events').read_text(),'1')
    def test_actual_process_death_at_each_durable_exchange_and_ack_boundary(self):
        for boundary in ('durable_building','candidate_files_durable','candidate_durable','capture_durable','durable_prepared','before_exchange','after_exchange','durable_exchanged','after_purge','durable_complete'):
            with self.subTest(boundary=boundary),tempfile.TemporaryDirectory(prefix='rsp-feed-crash-') as directory:
                root=pathlib.Path(directory).resolve();host=FakeHost(root,True)
                pid=os.fork()
                if pid==0:
                    host.kill_at=boundary
                    try:R.Engine(host,CONTRACT,MANIFEST).execute('apply')
                    except BaseException:os._exit(3)
                    os._exit(4)
                _,status=os.waitpid(pid,0);self.assertTrue(os.WIFSIGNALED(status));self.assertEqual(os.WTERMSIG(status),signal.SIGKILL)
                fresh=FakeHost(root);mode='resume' if (fresh.run/'journal.json').exists() else 'apply'
                result=R.Engine(fresh,CONTRACT,MANIFEST).execute(mode)
                self.assertEqual(result['state'],'complete')
                R.tree(fresh.live,CONTRACT['after'],fresh.uid,fresh.device,fresh.gid)
                R.tree(fresh.run/'exchange-tree',CONTRACT['before'],fresh.uid,fresh.device,fresh.gid)
                self.assertEqual((root/'options-current').read_bytes(),b'synthetic-token-canary-options-and-cache\n')
                self.assertEqual(int((root/'purge-events').read_text()),2 if boundary=='after_purge' else 1)
    def test_failed_purge_stays_exchanged_and_restart_retries_it(self):
        (self.root/'purge-fail').touch()
        with self.assertRaisesRegex(R.Refusal,'synthetic_purge_failed'):self.engine().execute('apply')
        self.assertEqual(self.engine().state()['phase'],'exchanged');self.assertions_after()
        (self.root/'purge-fail').unlink();self.assertEqual(self.engine(FakeHost(self.root)).execute('resume')['state'],'complete')
        self.assertEqual((self.root/'purge-events').read_text(),'2')
    def test_foreign_runtime_file_stops_before_mutation(self):
        foreign=self.h.live/'foreign-owned-file';foreign.write_bytes(b'keep me')
        with self.assertRaisesRegex(R.Refusal,'foreign_file'):self.engine().execute('apply')
        self.assertEqual(foreign.read_bytes(),b'keep me');self.assertFalse((self.h.run/'journal.json').exists())
    def test_check_refuses_foreign_candidate_without_claiming_eligibility(self):
        directory=self.h.run/'exchange-tree';directory.mkdir(mode=0o755);foreign=directory/'foreign';foreign.write_bytes(b'preserved foreign candidate')
        with self.assertRaises(R.Refusal):self.engine().execute('check')
        self.assertEqual(foreign.read_bytes(),b'preserved foreign candidate');self.assertFalse((self.h.run/'journal.json').exists())
    def test_unknown_empty_runtime_directory_is_preserved_and_refused(self):
        p=self.h.live/'foreign';p.mkdir()
        with self.assertRaisesRegex(R.Refusal,'foreign_directory'):self.engine().execute('apply')
        self.assertTrue(p.is_dir())
    def test_live_hash_drift_is_not_overwritten(self):
        p=self.h.live/'readme.txt';p.write_bytes(b'owner edit')
        with self.assertRaisesRegex(R.Refusal,'tree_hash_drift'):self.engine().execute('apply')
        self.assertEqual(p.read_bytes(),b'owner edit')
    def test_live_symlink_does_not_touch_foreign_target(self):
        target=self.root/'foreign';target.write_bytes(b'foreign bytes')
        p=self.h.live/'readme.txt';p.unlink();p.symlink_to(target)
        with self.assertRaises(R.Refusal):self.engine().execute('apply')
        self.assertEqual(target.read_bytes(),b'foreign bytes')
    def test_hardlinked_runtime_file_is_refused(self):
        p=self.h.live/'readme.txt';os.link(p,self.root/'hardlink')
        with self.assertRaisesRegex(R.Refusal,'regular_file_metadata'):self.engine().execute('apply')
    def test_directory_symlink_is_refused_without_enumerating_foreign_root(self):
        p=self.h.live/'foreign-dir';p.symlink_to(self.root,target_is_directory=True)
        with self.assertRaisesRegex(R.Refusal,'tree_symlink'):self.engine().execute('apply')
    def test_options_change_after_intent_refuses_resume_and_preserves_new_bytes(self):
        (self.root/'purge-fail').touch()
        with self.assertRaises(R.Refusal):self.engine().execute('apply')
        (self.root/'options-current').write_bytes(b'concurrent owner edit')
        with self.assertRaisesRegex(R.Refusal,'options_changed'):self.engine(FakeHost(self.root)).execute('resume')
        self.assertEqual((self.root/'options-current').read_bytes(),b'concurrent owner edit');self.assertions_after()
    def test_options_late_preimage_change_stops_before_exchange(self):
        def changed(name):
            if name=='durable_prepared':(self.root/'options-current').write_bytes(b'new owner state')
        self.h.hook=changed
        with self.assertRaisesRegex(R.Refusal,'options_changed'):self.engine().execute('apply')
        R.tree(self.h.live,CONTRACT['before'],self.h.uid,self.h.device,self.h.gid)
        self.assertEqual((self.root/'options-current').read_bytes(),b'new owner state')
    def test_cache_drift_refuses_without_deleting_cached_or_foreign_bytes(self):
        (self.root/'purge-fail').touch()
        with self.assertRaises(R.Refusal):self.engine().execute('apply')
        (self.root/'cached-rendition').write_bytes(b'new background cache')
        with self.assertRaisesRegex(R.Refusal,'cache_changed'):self.engine(FakeHost(self.root)).execute('resume')
        self.assertEqual((self.root/'cached-rendition').read_bytes(),b'new background cache')
    def test_corrupt_encrypted_capture_is_refused(self):
        (self.root/'purge-fail').touch()
        with self.assertRaises(R.Refusal):self.engine().execute('apply')
        (self.h.run/'options.enc').write_bytes(b'corrupt ciphertext')
        with self.assertRaisesRegex(R.Refusal,'encrypted_capture_drift'):self.engine(FakeHost(self.root)).execute('resume')
    def test_journal_operator_source_pin_is_required_for_resume(self):
        (self.root/'purge-fail').touch()
        with self.assertRaises(R.Refusal):self.engine().execute('apply')
        other=copy.deepcopy(MANIFEST);other['files']['rsp-feed-release.py']='d'*64
        with self.assertRaisesRegex(R.Refusal,'journal_source_authority'):self.engine(FakeHost(self.root),other).execute('resume')
    def test_unjournaled_new_tree_is_not_adopted(self):
        R.unpack(NEW_ZIP,self.h.run/'exchange-tree',CONTRACT['after'],self.h.uid,self.h.gid)
        R.exchange(self.h.run/'exchange-tree',self.h.live)
        with self.assertRaises(R.Refusal):self.engine().execute('apply')
        self.assertFalse((self.h.run/'journal.json').exists())
    def test_source_hash_drift_after_exchange_is_not_swapped_away(self):
        (self.root/'purge-fail').touch()
        with self.assertRaises(R.Refusal):self.engine().execute('apply')
        p=self.h.live/'readme.txt';p.write_bytes(b'new live owner edit')
        with self.assertRaises(R.Refusal):self.engine(FakeHost(self.root)).execute('resume')
        self.assertEqual(p.read_bytes(),b'new live owner edit')
    def test_zip_path_escape_and_symlink_and_duplicate_are_rejected_before_tree_creation(self):
        for kind in ('traversal','absolute','symlink','duplicate'):
            with self.subTest(kind=kind):
                raw=io.BytesIO()
                with zipfile.ZipFile(raw,'w') as z:
                    for index,(name,body) in enumerate(sorted(AFTER.items())):
                        item=zipfile.ZipInfo(R.SLUG+'/'+name);item.external_attr=(stat.S_IFREG|0o644)<<16
                        if index==0:
                            if kind=='traversal':item.filename=R.SLUG+'/../foreign'
                            elif kind=='absolute':item.filename='/foreign'
                            elif kind=='symlink':item.external_attr=(stat.S_IFLNK|0o777)<<16
                            elif kind=='duplicate':item.filename=R.SLUG+'/'+sorted(AFTER)[1]
                        z.writestr(item,body)
                target=self.h.run/('invalid-'+kind)
                with self.assertRaises(R.Refusal):R.unpack(raw.getvalue(),target,CONTRACT['after'],self.h.uid,self.h.gid)
                self.assertFalse(target.exists());self.assertFalse((self.root/'foreign').exists())
    def test_runtime_package_has_exact29_files_and_excludes_tools(self):
        with zipfile.ZipFile(io.BytesIO(NEW_ZIP)) as z:
            self.assertEqual(len(z.namelist()),29)
            self.assertFalse(any('/tools/' in p or '/tests/' in p for p in z.namelist()))
    def test_production_cli_rejects_destination_override_without_connecting(self):
        r=subprocess.run([sys.executable,'-I','-B',str(ROOT/'tools/rsp-feed-release.py'),'--root',str(self.root/'escape')],capture_output=True)
        self.assertEqual(r.returncode,2);self.assertIn(b'RSP_FEED_REFUSED usage_',r.stderr)
        self.assertFalse((self.root/'escape').exists())
    def test_completed_replay_allows_normal_later_data_refresh_without_writes(self):
        self.engine().execute('apply');inode=self.h.live.stat().st_ino
        (self.root/'options-current').write_bytes(b'legitimate later refresh')
        (self.root/'cached-rendition').write_bytes(b'later background rendition')
        host=FakeHost(self.root)
        def forbidden():raise AssertionError('completed replay may not read mutable options/cache/admission')
        host.options=forbidden;host.cache=forbidden;host.preflight=forbidden
        for action in ('check','apply','resume'):
            result=self.engine(host).execute(action)
            self.assertEqual(result['state'],'complete');self.assertEqual(result['preservation'],'verified_at_commit')
        self.assertEqual((self.root/'purge-events').read_text(),'1');self.assertEqual(inode,self.h.live.stat().st_ino)
        self.assertEqual((self.root/'options-current').read_bytes(),b'legitimate later refresh')
    def test_actual_process_death_mid_third_runtime_file_keeps_partial_evidence_and_resumes(self):
        pid=os.fork()
        if pid==0:
            original=R.os.open;writes=0
            def killed(path,flags,*args,**kwargs):
                nonlocal writes
                fd=original(path,flags,*args,**kwargs)
                if '.candidate-' in str(path) and flags & os.O_WRONLY:
                    writes+=1
                    if writes==3:os.kill(os.getpid(),signal.SIGKILL)
                return fd
            R.os.open=killed
            try:self.engine().execute('apply')
            except BaseException:os._exit(3)
            os._exit(4)
        _,status=os.waitpid(pid,0);self.assertTrue(os.WIFSIGNALED(status));self.assertEqual(os.WTERMSIG(status),signal.SIGKILL)
        self.assertEqual(self.engine().state()['phase'],'building')
        partial=list(self.h.run.glob('.candidate-*'));self.assertEqual(len(partial),1)
        self.assertFalse((self.h.run/'exchange-tree').exists())
        R.tree(self.h.live,CONTRACT['before'],self.h.uid,self.h.device,self.h.gid)
        result=self.engine(FakeHost(self.root)).execute('resume')
        self.assertEqual(result['state'],'complete');self.assertTrue(partial[0].exists());self.assertions_after()
    def test_building_restart_recaptures_current_data_only_with_exact_old_live_tree(self):
        def pause(name):
            if name=='durable_building':raise R.Refusal('synthetic_pause')
        self.h.hook=pause
        with self.assertRaisesRegex(R.Refusal,'synthetic_pause'):self.engine().execute('apply')
        (self.root/'options-current').write_bytes(b'legitimate edit before any prepared intent')
        (self.root/'cached-rendition').write_bytes(b'new cache before prepared intent')
        result=self.engine(FakeHost(self.root)).execute('resume')
        self.assertEqual(result['optionsSha256'],R.sha((self.root/'options-current').read_bytes()));self.assertions_after()
    def test_building_restart_cannot_adopt_edited_old_live_tree(self):
        def pause(name):
            if name=='durable_building':raise R.Refusal('synthetic_pause')
        self.h.hook=pause
        with self.assertRaises(R.Refusal):self.engine().execute('apply')
        p=self.h.live/'readme.txt';p.write_bytes(b'owner changed old source')
        with self.assertRaisesRegex(R.Refusal,'tree_hash_drift'):self.engine(FakeHost(self.root)).execute('resume')
        self.assertEqual(p.read_bytes(),b'owner changed old source')
    def test_partial_stage_attempt_bound_never_removes_evidence(self):
        for n in range(8):(self.h.run/('.candidate-'+format(n,'032x'))).mkdir()
        with self.assertRaisesRegex(R.Refusal,'candidate_attempt_bound'):self.engine().execute('apply')
        self.assertEqual(len(list(self.h.run.glob('.candidate-*'))),8)
        R.tree(self.h.live,CONTRACT['before'],self.h.uid,self.h.device,self.h.gid)
    def test_real_purge_adapter_refuses_non_success_page_purge_before_redis(self):
        with mock.patch.object(R.time,'sleep'),mock.patch.object(R,'checked',return_value=b'500') as calls:
            with self.assertRaisesRegex(R.Refusal,'rsp_page_purge_failed'):R.Host.purge(self.h)
        self.assertEqual(calls.call_count,1)
    def test_real_purge_adapter_rejects_foreign_prefix_and_unlink_errors(self):
        for response,label in [(["0",["customer:private"]],'foreign_redis_key'),(["0",["rsp-plainwp:a"]],'redis_unlink_failed')]:
            with self.subTest(label=label):
                def command(args,raw=None,timeout=30):
                    if args[0]=='curl':return b'200'
                    if 'SCAN' in args:return json.dumps(response).encode()
                    if 'UNLINK' in args:return b'ERR synthetic failure'
                    raise AssertionError('unexpected mutation')
                with mock.patch.object(R.time,'sleep'),mock.patch.object(R,'checked',side_effect=command):
                    with self.assertRaisesRegex(R.Refusal,label):R.Host.purge(self.h)
    def test_actual_purge_partial_failure_keeps_journal_for_safe_restart_retry(self):
        attempts=[];fail=True
        def command(args,raw=None,timeout=30):
            nonlocal fail
            if args[0]=='openssl':return original(args,raw,timeout)
            if args[0]=='curl':return b'200'
            if 'SCAN' in args:
                cursor=args[args.index('SCAN')+1]
                return json.dumps(['1',['rsp-plainwp:first']] if cursor=='0' else ['0',['rsp-plainwp:second']]).encode()
            if 'UNLINK' in args:
                key=args[-1];self.assertTrue(key.startswith('rsp-plainwp:'));attempts.append(key)
                if key.endswith('second') and fail:return b'ERR partial failure'
                return b'1'
            raise AssertionError('unexpected command')
        original=R.checked;self.h.purge=lambda:R.Host.purge(self.h)
        with mock.patch.object(R.time,'sleep'),mock.patch.object(R,'checked',side_effect=command):
            with self.assertRaisesRegex(R.Refusal,'redis_unlink_failed'):self.engine().execute('apply')
            self.assertEqual(self.engine().state()['phase'],'exchanged');fail=False
            self.assertEqual(self.engine().execute('resume')['state'],'complete')
        self.assertEqual(attempts,['rsp-plainwp:first','rsp-plainwp:second','rsp-plainwp:first','rsp-plainwp:second'])
        self.assertions_after()
    def test_purge_scan_cursor_loop_is_bounded(self):
        def command(args,raw=None,timeout=30):
            return b'200' if args[0]=='curl' else b'["4",[]]'
        with mock.patch.object(R.time,'sleep'),mock.patch.object(R,'checked',side_effect=command):
            with self.assertRaisesRegex(R.Refusal,'redis_cursor_loop'):R.Host.purge(self.h)
    def test_real_cache_snapshot_keeps_foreign_bytes_and_refuses_them(self):
        directory=self.root/'media';directory.mkdir();good=directory/('a'*64+'-320.jpg');good.write_bytes(b'owned cached bytes')
        with mock.patch.object(R,'CACHE',directory):
            result=R.Host.cache(self.h);self.assertEqual(result['files'],1)
            foreign=directory/'foreign.txt';foreign.write_bytes(b'foreign cache directory bytes')
            with self.assertRaisesRegex(R.Refusal,'cache_file_scope'):R.Host.cache(self.h)
            self.assertEqual(foreign.read_bytes(),b'foreign cache directory bytes');self.assertEqual(good.read_bytes(),b'owned cached bytes')
    def test_real_cache_snapshot_rejects_symlink_and_never_follows_it(self):
        directory=self.root/'media';directory.mkdir();target=self.root/'outside';target.write_bytes(b'foreign')
        (directory/('a'*64+'-320.jpg')).symlink_to(target)
        with mock.patch.object(R,'CACHE',directory):
            with self.assertRaisesRegex(R.Refusal,'regular_file_metadata'):R.Host.cache(self.h)
        self.assertEqual(target.read_bytes(),b'foreign')
    def test_option_adapter_keeps_secret_unicode_bytes_in_stdin_and_checks_site_identity(self):
        identifier='shootcal-social-feed/shootcal-instagram-feed.php'
        active=('a:1:{i:0;s:'+str(len(identifier))+':"'+identifier+'";}').encode()
        token='synthetic-secret-💡-日本語'.encode()+b'\x00'
        def row(i,name,value):return str(i).encode()+b'\t'+name.hex().upper().encode()+b'\t'+value.hex().upper().encode()+b'\t6E6F\n'
        expected=row(1,b'active_plugins',active)+row(2,b'shootcal_instagram_feed_options',token)
        original=R.checked
        def command(args,raw=None,timeout=30):
            self.assertNotIn('synthetic-secret',str(args));self.assertNotIn('💡',str(args))
            if args[0]=='php8.5':return original(['php',*args[1:]],raw,timeout)
            self.assertIn('--skip-plugins',args);self.assertIn('--skip-themes',args);self.assertIn('--skip-packages',args);self.assertIn('sendmail_path=/bin/false',args)
            if 'config' in args:return b'wp_\n' if 'table_prefix' in args else b'rsp_blog\n'
            self.assertIn('db',args);self.assertIn('--raw',args);return expected
        with mock.patch.object(R,'checked',side_effect=command):raw,summary=R.Host.options(self.h)
        self.assertEqual(raw,expected);self.assertEqual(summary['activePluginCount'],1);self.assertTrue(summary['feedActive']);self.assertEqual(summary['optionsSha256'],R.sha(expected))
    def test_wrong_database_refuses_before_option_query(self):
        with mock.patch.object(R,'checked',return_value=b'foreign_database') as call:
            with self.assertRaisesRegex(R.Refusal,'wordpress_database_identity'):R.Host.options(self.h)
            self.assertEqual(call.call_count,1)

class BackupProofTests(unittest.TestCase):
    def test_actual_index_join_and_success_contract(self):
        config={'servers':[{'key':'consolidated','url':'http://127.0.0.1:19899','username':'synthetic','password':'synthetic-private'}]}
        snapshot='a'*64
        good={'id':'9','planId':'amd-hourly-databases-config','status':'STATUS_SUCCESS','operationBackup':{},'snapshotId':snapshot,'unixTimeStartMs':'800000','unixTimeEndMs':'900000'}
        index={'id':'10','status':'STATUS_SUCCESS','snapshotId':snapshot,'operationIndexSnapshot':{}}
        class Response:
            status=200
            def __init__(self,rows):self.raw=json.dumps({'operations':rows}).encode()
            def __enter__(self):return self
            def __exit__(self,*args):pass
            def read(self,bound):return self.raw[:bound]
        for mutation,expected in [('good',True),('missing_index',False),('other_index',False),('warning',False),('dryrun',False),('errors',False),('stale',False)]:
            with self.subTest(mutation=mutation):
                backup=copy.deepcopy(good);indexed=copy.deepcopy(index);rows=[backup,indexed]
                if mutation=='missing_index':rows=[backup]
                if mutation=='other_index':indexed['snapshotId']='b'*64
                if mutation=='warning':backup['status']='STATUS_WARNING'
                if mutation=='dryrun':backup['operationBackup']['dryRun']=True
                if mutation=='errors':backup['operationBackup']['errors']=['synthetic private error']
                if mutation=='stale':backup['unixTimeEndMs']='100000'
                def open_request(request,timeout):
                    self.assertEqual(request.full_url,'http://127.0.0.1:19899/v1.Backrest/GetOperations')
                    self.assertEqual(json.loads(request.data)['selector']['planId'],'amd-hourly-databases-config')
                    self.assertEqual(request.get_method(),'POST');return Response(rows)
                opener=mock.Mock();opener.open.side_effect=open_request
                with mock.patch.object(R,'regular',return_value=json.dumps(config).encode()),mock.patch.object(R.urllib.request,'build_opener',return_value=opener):
                    if expected:self.assertEqual(R.indexed_backup({'last_snapshot':'a'*8},1000)['operation'],'9')
                    else:
                        with self.assertRaises(R.Refusal):R.indexed_backup({'last_snapshot':'a'*8},10000 if mutation=='stale' else 1000)

class AdmissionTests(unittest.TestCase):
    def fixture(self,minute=5,hour=22):
        now=R.datetime.datetime(2026,11,9,hour,minute,0,tzinfo=R.datetime.timezone.utc).timestamp()
        plans=[{'id':name,'last_status':'success','failures_24h':0,'last_success_at':now-300,'last_snapshot':'a'*8} for name in ('amd-hourly-databases-config','amd-daily-owner-media','amd-daily-shootcal-files')]
        status={'generated_at':now-10,'servers':[{'key':'consolidated','reachable':True,'plans':plans}]}
        return now,status
    def admission(self,minute=5,hour=22,hold=False,change=None,childcode=1,expire_after_reads=False,fpm_root=None):
        now,status=self.fixture(minute,hour)
        if change:change(status)
        clock=[now]
        host=SimpleNamespace(identity=lambda:None,deadline=R.time.monotonic()+120)
        def command(args,raw=None,timeout=30):
            if args[0]=='systemctl':return b'ActiveState=active\nMainPID=42\n'
            if args[0]=='php8.5':
                if expire_after_reads:clock[0]+=1800
                return b'["1","2"]'
            raise AssertionError('unexpected external command')
        host.command=command;host.window=lambda: R.Host.window(host)
        actual_read=R.regular;actual_fpm=R.verify_fpm_config
        def read(path,*args,**kwargs):
            if path==pathlib.Path('/var/lib/shootcal/backup-status/backrest.json'):return json.dumps(status).encode()
            return actual_read(path,*args,**kwargs)
        def fpm_check(root):
            if fpm_root is not None:return actual_fpm(root,owner=os.getuid())
        with mock.patch.object(R.time,'time',side_effect=lambda:clock[0]),mock.patch.object(R.os.path,'lexists',return_value=hold),mock.patch.object(R,'regular',side_effect=read),mock.patch.object(R,'FPM_ROOT',fpm_root or R.FPM_ROOT),mock.patch.object(R,'verify_fpm_config',side_effect=fpm_check),mock.patch.object(R,'checked',side_effect=command),mock.patch.object(R.subprocess,'run',return_value=SimpleNamespace(returncode=childcode)),mock.patch.object(R,'indexed_backup',return_value={'operation':'9','indexOperation':'10','snapshotSha256':'b'*64}):
            R.Host.preflight(host)
    def fpm_fixture(self,directory):
        root=pathlib.Path(directory).resolve()/'ownerwp';pool=root/'pool.d';pool.mkdir(parents=True)
        (root/'php-fpm.conf').write_text('[global]\ninclude = '+str(pool/'*.conf')+'\n')
        (pool/'axis.conf').write_text('[axis]\nuser = axisblog\n')
        (pool/'rsp.conf').write_text('[rsp]\nuser = rspblog\n')
        return root
    def test_host_preflight_ignores_large_unrelated_nginx_map(self):
        with tempfile.TemporaryDirectory(prefix='rsp-fpm-graph-') as directory:
            root=self.fpm_fixture(directory);nginx=root/'nginx';nginx.mkdir()
            irrelevant=nginx/'maps-rsp-old-media.conf'
            with irrelevant.open('wb') as stream:stream.truncate(5844845)
            self.admission(fpm_root=root)
            self.assertEqual(irrelevant.stat().st_size,5844845)
    def test_host_preflight_rejects_value_and_flag_opcache_overrides_in_each_pool(self):
        for pool in ('axis.conf','rsp.conf'):
            for directive in ('php_value[opcache.revalidate_freq] = 0','php_admin_value[opcache.revalidate_freq] = 0','php_flag[opcache.validate_timestamps] = off','php_admin_flag[opcache.validate_timestamps] = off',"php_admin_flag['opcache.validate_timestamps'] = off"):
                with self.subTest(pool=pool,directive=directive),tempfile.TemporaryDirectory(prefix='rsp-fpm-graph-') as directory:
                    root=self.fpm_fixture(directory)
                    with (root/'pool.d'/pool).open('a') as stream:stream.write(directive+'\n')
                    with self.assertRaisesRegex(R.Refusal,'opcache_pool_override'):self.admission(fpm_root=root)
    def test_host_preflight_rejects_unknown_root_or_nested_pool_include(self):
        for relative,line in [('php-fpm.conf','include = /etc/php/extra.conf'),('pool.d/rsp.conf','include = /etc/php/extra.conf'),('php-fpm.conf','include = "'+str(R.FPM_ROOT/'pool.d/*.conf')+'"'),('pool.d/axis.conf','INCLUDE = relative.conf ; unsupported include')]:
            with self.subTest(relative=relative,line=line),tempfile.TemporaryDirectory(prefix='rsp-fpm-graph-') as directory:
                root=self.fpm_fixture(directory)
                with (root/relative).open('a') as stream:stream.write(line+'\n')
                with self.assertRaisesRegex(R.Refusal,'fpm_include_graph'):self.admission(fpm_root=root)
    def test_host_preflight_rejects_extra_pool_in_actual_wildcard(self):
        with tempfile.TemporaryDirectory(prefix='rsp-fpm-graph-') as directory:
            root=self.fpm_fixture(directory);(root/'pool.d/extra.conf').write_text('[extra]\n')
            with self.assertRaisesRegex(R.Refusal,'fpm_pool_inventory'):self.admission(fpm_root=root)
    def test_host_preflight_keeps_existing_read_limit_for_actual_fpm_config(self):
        with tempfile.TemporaryDirectory(prefix='rsp-fpm-graph-') as directory:
            root=self.fpm_fixture(directory)
            with (root/'pool.d/rsp.conf').open('ab') as stream:stream.truncate(R.FILE_LIMIT+1)
            with self.assertRaisesRegex(R.Refusal,'regular_file_metadata'):self.admission(fpm_root=root)
    def test_host_preflight_refuses_symlink_pool_file_without_reading_target(self):
        with tempfile.TemporaryDirectory(prefix='rsp-fpm-graph-') as directory:
            root=self.fpm_fixture(directory);target=root/'foreign.conf';target.write_text('private foreign configuration')
            pool=root/'pool.d/rsp.conf';pool.unlink();pool.symlink_to(target)
            with self.assertRaisesRegex(R.Refusal,'regular_file_metadata'):self.admission(fpm_root=root)
            self.assertEqual(target.read_text(),'private foreign configuration')
    def test_real_host_constructor_uses_verified_distinct_rsp_uid_and_gid(self):
        with mock.patch.object(R.pwd,'getpwnam',return_value=SimpleNamespace(pw_uid=995,pw_gid=980)):
            host=R.Host(pathlib.Path('/synthetic-private-run'),MANIFEST)
            self.assertEqual((host.uid,host.gid),(995,980))
        for pair in ((995,995),(0,0),(994,980)):
            with self.subTest(pair=pair),mock.patch.object(R.pwd,'getpwnam',return_value=SimpleNamespace(pw_uid=pair[0],pw_gid=pair[1])):
                with self.assertRaisesRegex(R.Refusal,'rsp_identity'):R.Host(pathlib.Path('/synthetic-private-run'),MANIFEST)
    def test_allowed_start_windows(self):
        for minute in (0,18,48,59):
            with self.subTest(minute=minute):self.admission(minute)
    def test_protected_hourly_window_and_lead_are_refused(self):
        for minute in (19,24,25,40,47):
            with self.subTest(minute=minute):
                with self.assertRaisesRegex(R.Refusal,'protected_backup_window'):self.admission(minute)
    def test_slow_admission_reads_cannot_reuse_an_expired_clock(self):
        with self.assertRaisesRegex(R.Refusal,'protected_backup_window'):self.admission(expire_after_reads=True)
    def test_daily_backup_and_image_lead_are_refused(self):
        for hour,minute in ((4,5),(4,10),(4,18),(5,55),(6,0),(6,10)):
            with self.subTest(hour=hour,minute=minute):
                with self.assertRaisesRegex(R.Refusal,'daily_backup_lead_window'):self.admission(minute,hour)
    def test_rsp_hold_refuses_before_commands(self):
        with self.assertRaisesRegex(R.Refusal,'rsp_hold'):self.admission(hold=True)
    def test_stale_status_refused(self):
        with self.assertRaisesRegex(R.Refusal,'backup_status_stale'):self.admission(change=lambda x:x.update(generated_at=x['generated_at']-500))
    def test_partial_failed_or_old_success_refused(self):
        for field,value in (('last_status','warning'),('last_status','error'),('failures_24h',1),('last_success_at',1),('last_snapshot','not-a-snapshot')):
            with self.subTest(field=field,value=value):
                with self.assertRaisesRegex(R.Refusal,'backup_success_required'):self.admission(change=lambda x:x['servers'][0]['plans'][0].update({field:value}))
    def test_actual_manager_child_or_pgrep_failure_refused(self):
        for result in (0,2):
            with self.subTest(result=result):
                with self.assertRaisesRegex(R.Refusal,'backup_manager_busy'):self.admission(childcode=result)
    def test_actual_kernel_shared_lock_busy_and_inode_is_preserved(self):
        with tempfile.TemporaryDirectory(prefix='rsp-lock-') as directory:
            lock=pathlib.Path(directory)/'shared.lock';lock.touch(mode=0o600);inode=lock.stat().st_ino
            ready_r,ready_w=os.pipe();done_r,done_w=os.pipe();pid=os.fork()
            if pid==0:
                os.close(ready_r);os.close(done_w)
                fd=os.open(lock,os.O_RDWR);R.fcntl.flock(fd,R.fcntl.LOCK_EX)
                os.write(ready_w,b'1');os.read(done_r,1);os._exit(0)
            os.close(ready_w);os.close(done_r);os.read(ready_r,1)
            original=R.os.fstat
            def root_metadata(fd):
                s=original(fd);return SimpleNamespace(st_mode=s.st_mode,st_uid=0,st_nlink=s.st_nlink)
            try:
                with mock.patch.object(R,'LOCK',lock),mock.patch.object(R.os,'fstat',side_effect=root_metadata):
                    with self.assertRaises(BlockingIOError):
                        with R.Host.lock(SimpleNamespace()):raise AssertionError('busy shared lock admitted')
                self.assertEqual(lock.stat().st_ino,inode)
            finally:
                os.write(done_w,b'1');os.close(done_w);os.close(ready_r);os.waitpid(pid,0)
    def test_shared_lock_symlink_is_never_followed(self):
        with tempfile.TemporaryDirectory(prefix='rsp-lock-') as directory:
            target=pathlib.Path(directory)/'foreign';target.write_bytes(b'preserve foreign lock bytes')
            link=pathlib.Path(directory)/'shared.lock';link.symlink_to(target)
            with mock.patch.object(R,'LOCK',link):
                with self.assertRaises(OSError):
                    with R.Host.lock(SimpleNamespace()):raise AssertionError('symlink lock admitted')
            self.assertEqual(target.read_bytes(),b'preserve foreign lock bytes')

class PackagingTests(unittest.TestCase):
    def test_real_clean_pushed_git_source_package_and_dirty_unpushed_refusal(self):
        with tempfile.TemporaryDirectory(prefix='rsp-package-git-') as directory:
            root=pathlib.Path(directory).resolve();repo=root/'checkout';remote=root/'remote.git'
            def git(*args):return subprocess.check_output(['git',*map(str,args)],stderr=subprocess.PIPE)
            git('clone','--quiet','--shared',ROOT,repo)
            git('init','--quiet','--bare',remote)
            git('-C',repo,'remote','set-url','origin',remote)
            git('-C',repo,'switch','--quiet','-c','synthetic-release')
            for name in ('rsp-feed-release.py','rsp-feed-release-contract.json','deploy-run.sh','README-rsp-release.md'):
                (repo/'tools').mkdir(exist_ok=True);shutil.copy2(ROOT/'tools'/name,repo/'tools'/name)
            shutil.copy2(ROOT/'.distignore',repo/'.distignore')
            git('-C',repo,'add','-f','tools','.distignore')
            git('-C',repo,'-c','user.name=Synthetic test','-c','user.email=synthetic@example.invalid','commit','--quiet','-m','Synthetic local release tool')
            command=[sys.executable,'-I','-B',str(repo/'tools/rsp-feed-release.py'),'package']
            r=subprocess.run(command,capture_output=True)
            self.assertEqual(r.returncode,2);self.assertIn(b'RSP_FEED_REFUSED',r.stderr)
            git('-C',repo,'push','--quiet','origin','synthetic-release')
            r=subprocess.run(command,capture_output=True);self.assertEqual(r.returncode,0,r.stderr)
            result=json.loads(r.stdout);output=pathlib.Path(result['packageDirectory'])
            try:
                raw=(output/'candidate.zip').read_bytes();self.assertEqual(R.sha(raw),result['zipSha256']);self.assertEqual(result['runtimeFiles'],29)
                with zipfile.ZipFile(io.BytesIO(raw)) as z:
                    self.assertEqual(set(z.namelist()),{R.SLUG+'/'+k for k in CONTRACT['after']})
                    for name,digest in CONTRACT['after'].items():self.assertEqual(R.sha(z.read(R.SLUG+'/'+name)),digest)
                manifest=json.loads((output/'manifest.json').read_bytes());self.assertEqual(manifest['sourceHead'],git('-C',repo,'rev-parse','HEAD').decode().strip())
                self.assertEqual(stat.S_IMODE(output.stat().st_mode),0o700)
            finally:shutil.rmtree(output)
            (repo/'readme.txt').write_text('dirty runtime')
            r=subprocess.run(command,capture_output=True);self.assertEqual(r.returncode,2);self.assertIn(b'source_tree_dirty',r.stderr)

if __name__=='__main__':unittest.main(verbosity=2)
