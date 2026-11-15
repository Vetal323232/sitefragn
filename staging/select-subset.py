"""Pick a representative subset of the public Store API dump for the local staging copy.

Usage: python3 -I select-subset.py <all.json> <reviews.json> <out.json>
Covers: homepage lists, every mood group, edge cases (min 1 ml, low stock, out of stock),
remnants («Залишки»), sets («Підбірки»), bottles («Тара»), gift wrap, reviewed products.
"""
import json
import re
import sys

all_path, reviews_path, out_path = sys.argv[1:4]
ps = json.load(open(all_path))
reviews = json.load(open(reviews_path))
by_id = {p['id']: p for p in ps}

TOP = [21996, 65658, 59289, 55271]  # «Топ продаж» tab on the live homepage
CLEAN = [22001, 41337, 32818, 21936, 21924, 21923, 21815, 23089]  # «Парфуми, які пахнуть чистотою»
EDGE_SLUGS = ['good-girl-supreme', 'bois-de-yuzu', 'tobacco-carnaval', 'my-devotion-edp-intense',
              'sikelia-nikos', 'moschino-toy-2-yummy', 'louis-vuitton-stellar-times',
              'baccarat', 'lost-cherry', 'chanel']
GROUPS = ['svizhi', 'tsytrusovi', 'solodki', 'vanilni', 'derevynni', 'kvitkovi', 'bilokvitkovi',
          'troiandovi', 'pudrovi', 'zeleni', 'akvatychni', 'priani', 'udovi', 'shkiriani']

pick = []


def add(pid):
    if pid in by_id and pid not in pick:
        pick.append(pid)


def cats(p):
    return [c['slug'] for c in p['categories']]


for pid in TOP + CLEAN:
    add(pid)
for p in ps:
    if any(s in p['permalink'] for s in EDGE_SLUGS):
        add(p['id'])
for g in GROUPS:
    n = 0
    for p in ps:
        if 'rozpyv' in cats(p) and p['is_in_stock'] and any(
                t['slug'] == g for a in p['attributes'] for t in a['terms']):
            add(p['id'])
            n += 1
            if n >= 6:
                break
for r in reviews[:40]:
    add(r['product_id'])
# Remnants («Залишки») have no public category in the Store API; they are recognised by name.
n = 0
for p in ps:
    if 'ЗАЛИШОК' in p['name'] and p['is_in_stock'] and n < 6:
        add(p['id'])
        n += 1
for c, k in (('pidbirky', 5), ('tara', 3), ('inshe', 3), ('sale', 6)):
    n = 0
    for p in ps:
        if c in cats(p) and p['is_in_stock']:
            add(p['id'])
            n += 1
            if n >= k:
                break

out = []
for pid in pick:
    p = by_id[pid]
    m = re.search(r'(\d+)', (p.get('stock_availability') or {}).get('text', ''))
    out.append({
        'id': p['id'], 'name': p['name'], 'slug': p['slug'], 'type': p['type'], 'sku': p['sku'],
        'description': p['description'], 'short_description': p['short_description'],
        'price': int(p['prices']['price']) / 100, 'regular_price': int(p['prices']['regular_price']) / 100,
        'sale_price': int(p['prices']['sale_price']) / 100 if p['on_sale'] else None,
        'in_stock': p['is_in_stock'], 'stock': int(m.group(1)) if m else None,
        'min': p['add_to_cart']['minimum'], 'max': p['add_to_cart']['maximum'],
        'categories': [{'name': c['name'], 'slug': c['slug']} for c in p['categories']],
        'attributes': [{'taxonomy': a['taxonomy'], 'name': a['name'],
                        'terms': [{'name': t['name'], 'slug': t['slug']} for t in a['terms']]}
                       for a in p['attributes'] if not a['has_variations']],
        'image': p['images'][0]['src'] if p['images'] else None,
        'rating': p['average_rating'], 'review_count': p['review_count'],
    })
rv = [{'product_id': r['product_id'], 'reviewer': r['reviewer'], 'review': r['review'], 'rating': r['rating'],
       'date': r['date_created']} for r in reviews if r['product_id'] in pick]
json.dump({'products': out, 'reviews': rv, 'top': TOP, 'clean': CLEAN}, open(out_path, 'w'), ensure_ascii=False)
print(len(out), 'products,', len(rv), 'reviews')
