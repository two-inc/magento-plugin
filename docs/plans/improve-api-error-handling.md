# Improve API validation error handling

## Problem

When the Two API returns a 400 with validation errors (e.g. invalid phone number), the plugin shows a generic message like "Phone Number is not valid." or "Something went wrong with your request to Two." instead of surfacing the actual detail from the API.

Example API response:
```
1 validation error for CreateOrderRequestSchema: buyer:
  Value error, Invalid phone number for GB: 00123456789
  [type=value_error, input_value={...}, input_type=dict]
```

User sees: "Something went wrong with your request to Two. Please try again later."

## Root cause

`Model/Two.php` has two methods that handle API errors:

### `getErrorFromResponse()` (line 296-330)

- Iterates `error_json` array from the API response
- Calls `getFieldFromLocStr()` for each error using only the `loc` field
- Ignores the `msg` field entirely — this is where the useful detail lives
- Falls back to generic error if `loc` doesn't match the hardcoded mapping

### `getFieldFromLocStr()` (line 342-360)

- Hardcoded mapping of `loc` arrays to generic messages:
  - `["buyer","representative","phone_number"]` -> "Phone Number is not valid."
  - `["buyer","company","organization_number"]` -> "Company ID is not valid."
  - etc.
- Returns `null` for any `loc` not in the mapping, triggering the generic fallback
- The mapping is incomplete and the messages discard the API's actual explanation

## Proposed fix

Use the `msg` field from `error_json` entries to build user-facing messages. Each entry has:

```json
{
  "loc": ["buyer", "representative", "phone_number"],
  "msg": "Value error, Invalid phone number for GB: 00123456789",
  "type": "value_error"
}
```

### Changes to `Model/Two.php`

1. **`getErrorFromResponse()`**: When iterating `error_json`, extract `msg` from each error and use it in the displayed message. Use the field mapping for the field name prefix, but append the API message for detail.

2. **`getFieldFromLocStr()`**: Refactor to return just the field name (e.g. "Phone Number") rather than a full sentence, so it can be composed with the API message.

3. **Fallback**: If `msg` is missing, fall back to the current generic field messages. If `loc` is unrecognised, use the `msg` directly without a field prefix.

### Example output after fix

- "Phone Number: Invalid phone number for GB: 00123456789"
- "Company ID: Organization number is not valid for country GB"

Instead of:

- "Phone Number is not valid."
- "Something went wrong with your request to Two."

## Files to modify

- `Model/Two.php` — `getErrorFromResponse()` and `getFieldFromLocStr()`

## Notes

- The `msg` from pydantic often starts with "Value error, " — consider stripping that prefix for cleaner display
- The JS-side error handling (`view/frontend/web/js/view/payment/method-renderer/two_payment.js` line 287) has a separate `processOrderIntentErrorResponse` for Order Intent calls — may want to align that too
