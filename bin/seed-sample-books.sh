#!/usr/bin/env bash
# Seed 12 sample books covering every age group, language and book type (including Nepali titles).
# Skips books whose SKU already exists, so it is safe to re-run.
# Usage: bash bin/seed-sample-books.sh [site-path]   (default: ~/Herd/bookstore-nepal)
# Requires: `wp bsn setup` has run (it creates the attributes).
set -euo pipefail

SITE="${1:-$HOME/Herd/bookstore-nepal}"
wp() { command wp --path="$SITE" "$@"; }

ADMIN="$(wp user list --role=administrator --field=ID | head -n 1)"
if [ -z "$ADMIN" ]; then
	echo "No administrator user found." >&2
	exit 1
fi

attr_id() {
	local id
	id="$(wp eval "echo (int) wc_attribute_taxonomy_id_by_name( '$1' );")"
	if [ "$id" = "0" ]; then
		echo "Attribute $1 missing; run: wp bsn setup" >&2
		exit 1
	fi
	echo "$id"
}
AGE="$(attr_id age-group)"
LANG_ID="$(attr_id language)"
TYPE="$(attr_id book-type)"

# Attribute values are term names, exactly as created by `wp bsn setup` (Catalog::ATTRIBUTES).
# sku|title|age|language|type|price|featured|author|pages|short description
BOOKS=(
	"BSN-001|The Little Yak's Big Day|4–7|English|Picture book|450|true|Anjali Shrestha|32|A small yak sets off across the high pastures and finds that every friend he meets has something to teach him."
	"BSN-002|मेरो पहिलो अक्षर|0–3|Nepali|Educational|350|false|सरिता थापा|24|क देखि ज्ञ सम्म — रंगीन चित्रसँग नेपाली अक्षर चिन्ने पहिलो किताब।"
	"BSN-003|Count the Momos|4–7|English|Activity|395|false|Prakash Gurung|28|Count, colour and match your way through a plate of steaming momos. Includes a page of stickers."
	"BSN-004|Rani and the River Kite|8–12|English|Story|650|true|Mira Rai|112|When Rani's kite drifts down the Trishuli, she follows it on an adventure that changes her village."
	"BSN-005|हिमालको कथा|8–12|Nepali|Story|550|true|लक्ष्मीप्रसाद देवकोटा|96|हिमालका गाउँहरूमा घुम्दै सुनिने साहसी बालबालिकाका कथाहरूको संग्रह।"
	"BSN-006|Colours of Dashain|4–7|English|Picture book|480|true|Sabina Karki|32|Kites, swings and tika — a bright picture book about a family's Dashain."
	"BSN-007|चरी र बादल|0–3|Nepali|Picture book|320|false|रमेश अधिकारी|20|सानो चरी र ठूलो बादलको मायालु चित्रकथा, सुत्ने बेलाका लागि।"
	"BSN-008|How Bridges Stand Up|8–12|English|Educational|1250|true|Dipesh Joshi|64|From rope bridges to steel spans: how Nepal's bridges carry people across rivers and gorges."
	"BSN-009|रङ भर्ने किताब|4–7|Nepali|Activity|300|false|कमला श्रेष्ठ|40|नेपालका जनावर, चाडपर्व र गाउँका चित्रमा रङ भर्नुहोस्।"
	"BSN-010|Baby's First Animals|0–3|English|Activity|420|false|Nisha Tamang|16|Lift-the-flap fun with yaks, red pandas and rhinos for the very youngest readers."
	"BSN-011|गन्ती गरौं|0–3|Nepali|Educational|280|true|विनोद पौडेल|20|एक देखि दस सम्म गन्न सिकाउने रमाइलो किताब।"
	"BSN-012|The Monkey Temple Mystery|8–12|English|Story|1800|true|Aarav Sharma|180|Three friends solve a puzzle hidden among the monkeys and prayer flags of Swayambhu."
)

created=0
for book in "${BOOKS[@]}"; do
	IFS='|' read -r sku title age lang type price featured author pages short <<< "$book"
	if [ -n "$(wp wc product list --user="$ADMIN" --sku="$sku" --field=id)" ]; then
		echo "skip: $sku $title"
		continue
	fi
	attributes="$(php -r 'echo json_encode([
		["id" => (int) $argv[1], "options" => [$argv[2]], "visible" => true],
		["id" => (int) $argv[3], "options" => [$argv[4]], "visible" => true],
		["id" => (int) $argv[5], "options" => [$argv[6]], "visible" => true],
	]);' "$AGE" "$age" "$LANG_ID" "$lang" "$TYPE" "$type")"
	meta="$(php -r 'echo json_encode([
		["key" => "_bsn_author", "value" => $argv[1]],
		["key" => "_bsn_page_count", "value" => $argv[2]],
	], JSON_UNESCAPED_UNICODE);' "$author" "$pages")"
	id="$(wp wc product create --user="$ADMIN" --porcelain \
		--name="$title" --sku="$sku" --regular_price="$price" --featured="$featured" \
		--short_description="$short" \
		--manage_stock=true --stock_quantity=10 \
		--attributes="$attributes" --meta_data="$meta")"
	echo "created: $sku #$id $title"
	created=$((created + 1))
done
echo "Done: $created book(s) created."
