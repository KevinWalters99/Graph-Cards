import requests

s = requests.Session()
r = s.post('http://192.168.0.215:8880/api/auth/login', json={
    'username': 'admin',
    'password': 'ACe!sysD#0kVnBWF'
})
print('Login:', r.status_code)
data = r.json()
s.headers['X-CSRF-Token'] = data.get('csrf_token', '')

# Get first livestream
ls = s.get('http://192.168.0.215:8880/api/livestreams').json()['data']
lid = ls[0]['livestream_id']
print('Auction:', ls[0]['stream_date'], '-', ls[0]['livestream_title'])

# Get auction summary
r = s.get('http://192.168.0.215:8880/api/cost-matrix/auction-summary', params={'livestream_id': lid})
print('Summary:', r.status_code)
d = r.json()
print('  Items:', d['total_items'])
print('  Revenue:', d['total_revenue'])
print('  Earnings:', d['total_earnings'])
print('  Fees:', d['total_fees'])
print('  Giveaways:', d['giveaway_count'], 'items,', d['giveaway_value'], 'value')
print('  Costs:', d['total_costs'])
print('  P/L:', d['profit_loss'])
print('  Avg Price:', d['avg_item_price'])
print('  Buyers:', d['unique_buyers'])
