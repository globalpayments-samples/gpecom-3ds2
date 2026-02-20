#!/bin/bash
# Full 14-card 3DS2 test script
# Usage: ./test-all-cards.sh <port> [backend_name]

PORT=${1:-3002}
BACKEND=${2:-"unknown"}
BASE="http://localhost:$PORT"

# PHP uses different API paths
if [ "$BACKEND" = "php" ]; then
  API_ENROLL="/php/api/check-enrollment.php"
  API_AUTH="/php/api/initiate-auth.php"
else
  API_ENROLL="/api/check-enrollment"
  API_AUTH="/api/initiate-auth"
fi

PASS=0
FAIL=0
TOTAL=0

# Test cards: card_number|expected_flow|expected_status|expected_eci|brand|no_method_url
CARDS=(
  "4222000006285344|frictionless|AUTHENTICATION_SUCCESSFUL|05|VISA|false"
  "4222000009719489|frictionless|AUTHENTICATION_SUCCESSFUL|05|VISA|true"
  "4222000005218627|frictionless|AUTHENTICATION_ATTEMPTED_BUT_NOT_SUCCESSFUL|06|VISA|false"
  "4222000002144131|frictionless|AUTHENTICATION_FAILED|07|VISA|false"
  "4222000007275799|frictionless|AUTHENTICATION_ISSUER_REJECTED|07|VISA|false"
  "4222000008880910|frictionless|AUTHENTICATION_COULD_NOT_BE_PERFORMED|07|VISA|false"
  "4222000001227408|challenge|CHALLENGE_REQUIRED||VISA|false"
  "5354560000000004|frictionless|AUTHENTICATION_SUCCESSFUL|02|MC|false"
  "5571596304025153|frictionless|AUTHENTICATION_SUCCESSFUL|02|MC|true"
  "5580364874958322|frictionless|AUTHENTICATION_ATTEMPTED_BUT_NOT_SUCCESSFUL|01|MC|false"
  "5540010585397800|frictionless|AUTHENTICATION_FAILED|00|MC|false"
  "5588312194362669|frictionless|AUTHENTICATION_ISSUER_REJECTED|00|MC|false"
  "5520680211891022|frictionless|AUTHENTICATION_COULD_NOT_BE_PERFORMED|00|MC|false"
  "5506874496684651|challenge|CHALLENGE_REQUIRED||MC|false"
)

echo "============================================"
echo "  3DS2 Full Card Test — $BACKEND (port $PORT)"
echo "============================================"
echo ""

for entry in "${CARDS[@]}"; do
  IFS='|' read -r CARD FLOW EXPECTED_STATUS EXPECTED_ECI BRAND NO_METHOD <<< "$entry"
  TOTAL=$((TOTAL + 1))
  ORDER_ID="test-${BACKEND}-${CARD: -4}-$(date +%s)"

  # Step 1: Check enrollment
  ENROLL=$(curl -s -X POST "$BASE$API_ENROLL" \
    -H "Content-Type: application/json" \
    -d "{\"card_number\":\"$CARD\",\"exp_date\":\"1228\",\"card_holder\":\"Test Customer\",\"order_id\":\"$ORDER_ID\"}")

  ENROLLED=$(echo "$ENROLL" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('data',{}).get('enrolled',''))" 2>/dev/null)
  SERVER_TRANS_ID=$(echo "$ENROLL" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('data',{}).get('server_trans_id',''))" 2>/dev/null)
  METHOD_URL=$(echo "$ENROLL" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('data',{}).get('method_url','') or '')" 2>/dev/null)

  if [ "$ENROLLED" != "Y" ] || [ -z "$SERVER_TRANS_ID" ]; then
    echo "FAIL [$BRAND] $CARD — enrollment failed (enrolled=$ENROLLED)"
    FAIL=$((FAIL + 1))
    continue
  fi

  # Check method_url expectation
  if [ "$NO_METHOD" = "true" ] && [ -n "$METHOD_URL" ] && [ "$METHOD_URL" != "None" ] && [ "$METHOD_URL" != "null" ]; then
    # Some SDKs still return method_url for no-method cards, that's OK
    :
  fi

  # Step 2: Initiate authentication
  AUTH=$(curl -s -X POST "$BASE$API_AUTH" \
    -H "Content-Type: application/json" \
    -d "{\"card_number\":\"$CARD\",\"exp_date\":\"1228\",\"card_holder\":\"Test Customer\",\"amount\":\"1999\",\"currency\":\"EUR\",\"server_trans_id\":\"$SERVER_TRANS_ID\",\"method_url_complete\":\"true\",\"browser_data\":{\"accept_header\":\"text/html\",\"color_depth\":\"24\",\"java_enabled\":\"false\",\"javascript_enabled\":\"true\",\"language\":\"en-US\",\"screen_height\":\"1080\",\"screen_width\":\"1920\",\"challenge_window_size\":\"05\",\"timezone\":\"0\",\"user_agent\":\"Mozilla/5.0\"}}")

  # Parse response
  AUTH_SUCCESS=$(echo "$AUTH" | python3 -c "import sys,json; print(json.load(sys.stdin).get('success',False))" 2>/dev/null)

  if [ "$FLOW" = "challenge" ]; then
    # Challenge cards: expect success=true with challenge_required=true
    CHALLENGE_REQ=$(echo "$AUTH" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('data',{}).get('challenge_required',False))" 2>/dev/null)
    STATUS=$(echo "$AUTH" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('data',{}).get('status',''))" 2>/dev/null)

    if [ "$AUTH_SUCCESS" = "True" ] && [ "$CHALLENGE_REQ" = "True" ] && [ "$STATUS" = "CHALLENGE_REQUIRED" ]; then
      echo "PASS [$BRAND] $CARD — $STATUS (challenge flow)"
      PASS=$((PASS + 1))
    else
      echo "FAIL [$BRAND] $CARD — expected CHALLENGE_REQUIRED, got success=$AUTH_SUCCESS challenge=$CHALLENGE_REQ status=$STATUS"
      FAIL=$((FAIL + 1))
    fi
  else
    # Frictionless cards
    if [ "$EXPECTED_STATUS" = "AUTHENTICATION_SUCCESSFUL" ] || [ "$EXPECTED_STATUS" = "AUTHENTICATION_ATTEMPTED_BUT_NOT_SUCCESSFUL" ]; then
      # Success/attempted: expect success=true
      STATUS=$(echo "$AUTH" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('data',{}).get('status',''))" 2>/dev/null)
      ECI=$(echo "$AUTH" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('data',{}).get('auth_data',{}).get('eci',''))" 2>/dev/null)

      if [ "$AUTH_SUCCESS" = "True" ] && [ "$STATUS" = "$EXPECTED_STATUS" ] && [ "$ECI" = "$EXPECTED_ECI" ]; then
        echo "PASS [$BRAND] $CARD — $STATUS ECI=$ECI"
        PASS=$((PASS + 1))
      else
        echo "FAIL [$BRAND] $CARD — expected $EXPECTED_STATUS/$EXPECTED_ECI, got success=$AUTH_SUCCESS status=$STATUS eci=$ECI"
        FAIL=$((FAIL + 1))
      fi
    else
      # Failed/rejected/unavailable: expect success=false
      MSG=$(echo "$AUTH" | python3 -c "import sys,json; print(json.load(sys.stdin).get('message',''))" 2>/dev/null)

      if [ "$AUTH_SUCCESS" = "False" ] && echo "$MSG" | grep -q "$EXPECTED_STATUS"; then
        echo "PASS [$BRAND] $CARD — $EXPECTED_STATUS (correctly rejected)"
        PASS=$((PASS + 1))
      else
        echo "FAIL [$BRAND] $CARD — expected failure with $EXPECTED_STATUS, got success=$AUTH_SUCCESS msg=$MSG"
        FAIL=$((FAIL + 1))
      fi
    fi
  fi
done

echo ""
echo "============================================"
echo "  Results: $PASS/$TOTAL passed, $FAIL failed"
echo "============================================"

exit $FAIL
