import os, sys
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'cardgraph', 'tools'))
from cg_config import NAS_IP

NAS_SHARE = rf'\\{NAS_IP}\web\cardgraph'
unix_path = '/volume1/web/cardgraph/archive/2026/20260220_S14_MAGS_AND_SLABS_2_STARTS'
rel = unix_path.replace('/volume1/web/cardgraph/', '')
session_dir = os.path.join(NAS_SHARE, rel.replace('/', os.sep))
audio_path = os.path.join(session_dir, 'audio', '20260221_Session14_SEG001.wav')
print(f'Session dir: {session_dir}')
print(f'Audio path: {audio_path}')
print(f'Dir exists: {os.path.exists(session_dir)}')
print(f'Audio exists: {os.path.exists(audio_path)}')
