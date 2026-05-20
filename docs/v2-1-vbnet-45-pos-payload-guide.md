# V2.1 Payload Generation Guide For VB.NET 4.5 POS

This guide shows how a VB.NET 4.5 POS application can generate a TSMS V2.1-compliant payload when the POS computes checksums directly from raw compact JSON strings.

Newtonsoft.Json may still be used for parsing TSMS responses, but it is not required for checksum generation in this approach.

## 1. Core Rule

Hashing a raw JSON string is valid only if that raw string is already the exact V2.1 canonical JSON string.

Do not hash the display JSON sent to logs, the pretty-printed request body, or a JSON string whose object keys are in POS insertion order.

The checksum string must use:

- Compact JSON with no whitespace.
- UTF-8 bytes.
- Object keys sorted alphabetically using ordinal comparison.
- Nested object keys sorted too, including objects inside `adjustments` and `taxes`.
- Array item order preserved.
- Money values represented as quoted strings with exactly two decimals.

## 2. Required Imports

```vbnet
Imports System
Imports System.Globalization
Imports System.Security.Cryptography
Imports System.Text
```

## 3. Canonical Field Order

When building raw strings manually, use these exact object key orders.

Transaction object without `payload_checksum`, used for the transaction checksum:

```text
adjustments
customer_code
gross_sales
hardware_id
net_sales
promo_status
receipt_no
taxes
transaction_id
transaction_timestamp
```

Transaction object with `payload_checksum`, used inside the root submission checksum and final request:

```text
adjustments
customer_code
gross_sales
hardware_id
net_sales
payload_checksum
promo_status
receipt_no
taxes
transaction_id
transaction_timestamp
```

Root payload without `payload_checksum`, used for the root checksum:

```text
submission_timestamp
submission_uuid
tenant_id
terminal_id
transaction
transaction_count
```

Root payload with `payload_checksum`, used for the final request body:

```text
payload_checksum
submission_timestamp
submission_uuid
tenant_id
terminal_id
transaction
transaction_count
```

Nested `adjustments` objects must use:

```text
adjustment_type
amount
```

Nested `taxes` objects must use:

```text
amount
tax_type
```

## 4. Helper Functions

Add this helper module to the POS project:

```vbnet
Public Module TsmsV21RawJson

    Public Function JsonString(value As String) As String
        If value Is Nothing Then
            Return "null"
        End If

        Dim sb As New StringBuilder()
        sb.Append(""""c)

        For Each ch As Char In value
            Select Case ch
                Case """"c
                    sb.Append("\""")
                Case "\"c
                    sb.Append("\\")
                Case ControlChars.Back
                    sb.Append("\b")
                Case ControlChars.FormFeed
                    sb.Append("\f")
                Case ControlChars.Lf
                    sb.Append("\n")
                Case ControlChars.Cr
                    sb.Append("\r")
                Case ControlChars.Tab
                    sb.Append("\t")
                Case Else
                    If AscW(ch) < 32 Then
                        sb.Append("\u")
                        sb.Append(AscW(ch).ToString("x4", CultureInfo.InvariantCulture))
                    Else
                        sb.Append(ch)
                    End If
            End Select
        Next

        sb.Append(""""c)
        Return sb.ToString()
    End Function

    Public Function Money(value As Decimal) As String
        Return JsonString(value.ToString("0.00", CultureInfo.InvariantCulture))
    End Function

    Public Function Sha256Hex(value As String) As String
        Using sha As SHA256 = SHA256.Create()
            Dim bytes As Byte() = Encoding.UTF8.GetBytes(value)
            Dim hash As Byte() = sha.ComputeHash(bytes)
            Dim sb As New StringBuilder(hash.Length * 2)

            For Each b As Byte In hash
                sb.Append(b.ToString("x2", CultureInfo.InvariantCulture))
            Next

            Return sb.ToString()
        End Using
    End Function

End Module
```

## 5. Build Canonical Transaction JSON

This function creates the canonical transaction JSON string. Use `Nothing` for the first checksum pass, then pass the computed transaction checksum for the root payload.

```vbnet
Public Function BuildTransactionJson(transactionId As String,
                                     payloadChecksum As String) As String
    Dim adjustments As String =
        "[" &
        "{""adjustment_type"":""promo_discount"",""amount"":""0.00""}," &
        "{""adjustment_type"":""senior_discount"",""amount"":""0.00""}," &
        "{""adjustment_type"":""pwd_discount"",""amount"":""0.00""}," &
        "{""adjustment_type"":""vip_card_discount"",""amount"":""0.00""}," &
        "{""adjustment_type"":""service_charge_distributed_to_employees"",""amount"":""0.00""}," &
        "{""adjustment_type"":""service_charge_retained_by_management"",""amount"":""0.00""}," &
        "{""adjustment_type"":""employee_discount"",""amount"":""0.00""}" &
        "]"

    Dim taxes As String =
        "[" &
        "{""amount"":""15.00"",""tax_type"":""VAT""}," &
        "{""amount"":""125.00"",""tax_type"":""VATABLE_SALES""}," &
        "{""amount"":""0.00"",""tax_type"":""SC_VAT_EXEMPT_SALES""}," &
        "{""amount"":""0.00"",""tax_type"":""OTHER_TAX""}" &
        "]"

    Dim sb As New StringBuilder()
    sb.Append("{")
    sb.Append("""adjustments"":").Append(adjustments)
    sb.Append(",""customer_code"":").Append(TsmsV21RawJson.JsonString("C-B1028"))
    sb.Append(",""gross_sales"":").Append(TsmsV21RawJson.JsonString("140.00"))
    sb.Append(",""hardware_id"":").Append(TsmsV21RawJson.JsonString("BUI-XTM80213"))
    sb.Append(",""net_sales"":").Append(TsmsV21RawJson.JsonString("125.00"))

    If payloadChecksum IsNot Nothing Then
        sb.Append(",""payload_checksum"":").Append(TsmsV21RawJson.JsonString(payloadChecksum))
    End If

    sb.Append(",""promo_status"":").Append(TsmsV21RawJson.JsonString("WITHOUT_APPROVAL"))
    sb.Append(",""receipt_no"":").Append(TsmsV21RawJson.JsonString("000001072840"))
    sb.Append(",""taxes"":").Append(taxes)
    sb.Append(",""transaction_id"":").Append(TsmsV21RawJson.JsonString(transactionId))
    sb.Append(",""transaction_timestamp"":").Append(TsmsV21RawJson.JsonString("2026-05-14T08:59:28Z"))
    sb.Append("}")

    Return sb.ToString()
End Function
```

## 6. Build Canonical Root Payload JSON

This function creates the canonical root JSON string. Use `Nothing` for the root checksum pass, then pass the computed root checksum for the final request body.

```vbnet
Public Function BuildRootPayloadJson(submissionUuid As String,
                                     transactionJsonWithChecksum As String,
                                     rootPayloadChecksum As String) As String
    Dim sb As New StringBuilder()
    sb.Append("{")

    If rootPayloadChecksum IsNot Nothing Then
        sb.Append("""payload_checksum"":").Append(TsmsV21RawJson.JsonString(rootPayloadChecksum)).Append(",")
    End If

    sb.Append("""submission_timestamp"":").Append(TsmsV21RawJson.JsonString("2026-05-14T08:59:28Z"))
    sb.Append(",""submission_uuid"":").Append(TsmsV21RawJson.JsonString(submissionUuid))
    sb.Append(",""tenant_id"":16")
    sb.Append(",""terminal_id"":97")
    sb.Append(",""transaction"":").Append(transactionJsonWithChecksum)
    sb.Append(",""transaction_count"":1")
    sb.Append("}")

    Return sb.ToString()
End Function
```

## 7. Generate A Complete Payload

```vbnet
Public Sub GeneratePayload()
    Dim submissionUuid As String = Guid.NewGuid().ToString()
    Dim transactionId As String = Guid.NewGuid().ToString()

    ' Step 1: Build transaction JSON without transaction payload_checksum.
    Dim transactionJsonForHash As String = BuildTransactionJson(transactionId, Nothing)

    ' Step 2: Hash the transaction canonical JSON.
    Dim transactionChecksum As String = TsmsV21RawJson.Sha256Hex(transactionJsonForHash)

    ' Step 3: Rebuild transaction JSON with transaction payload_checksum.
    Dim transactionJsonWithChecksum As String = BuildTransactionJson(transactionId, transactionChecksum)

    ' Step 4: Build root canonical JSON without root payload_checksum.
    Dim rootJsonForHash As String = BuildRootPayloadJson(submissionUuid, transactionJsonWithChecksum, Nothing)

    ' Step 5: Hash the root canonical JSON.
    Dim rootChecksum As String = TsmsV21RawJson.Sha256Hex(rootJsonForHash)

    ' Step 6: Build final request body with root payload_checksum.
    Dim finalPayloadJson As String = BuildRootPayloadJson(submissionUuid, transactionJsonWithChecksum, rootChecksum)

    Console.WriteLine(finalPayloadJson)
End Sub
```

The `finalPayloadJson` string is already compact JSON and can be sent as the request body with `Content-Type: application/json`.

## 8. Expected Checksum Process

The POS must compute hashes from these exact strings:

```text
transactionChecksum = SHA256(BuildTransactionJson(transactionId, Nothing))
rootChecksum = SHA256(BuildRootPayloadJson(submissionUuid, BuildTransactionJson(transactionId, transactionChecksum), Nothing))
```

Then the final request is:

```text
BuildRootPayloadJson(submissionUuid, BuildTransactionJson(transactionId, transactionChecksum), rootChecksum)
```

## 9. Important POS Behavior

Use a new `submission_uuid` for each new API submission attempt.

Use a new `transaction_id` only for a new sale event. If the POS is retrying the same sale after a timeout or network failure, reuse the original `transaction_id`. Changing `transaction_id` while keeping the same receipt can trigger duplicate or conflict behavior.

Keep `hardware_id` inside `transaction`. Putting it only at the root will not satisfy the V2.1 transaction contract.

## 10. Validate Against TSMS Locally

After generating a payload, save it as `payload.json` and run this in the TSMS development environment:

```bash
php scripts/validate_and_correct_payload.php payload.json
```

Expected output:

```text
Payload is valid.
```

If validation fails, compare:

- The transaction canonical string used for `transactionChecksum`.
- The root canonical string used for `rootChecksum`.
- The final request body.

Most checksum failures come from hashing one string and sending a different string.

## 11. Common Raw JSON Mistakes

| Mistake | Why It Fails | Fix |
| :--- | :--- | :--- |
| Hashing final JSON including `payload_checksum` | Each checksum excludes the checksum being computed | Build separate hash strings without that field |
| Hashing root payload before adding transaction checksum | Root hash must include `transaction.payload_checksum` | Compute transaction checksum first |
| Using POS insertion order | V2.1 requires sorted object keys | Follow the canonical field order in this guide |
| Leaving nested tax objects as `tax_type, amount` | Nested tax keys must also be sorted | Use `amount, tax_type` |
| Reversing nested adjustment objects | `adjustment_type` sorts before `amount` by ordinal comparison | Use `adjustment_type, amount` |
| Adding spaces or line breaks before hashing | Whitespace changes the hash | Hash compact JSON only |
| Using current culture for decimals | Some locales emit comma decimals | Use invariant `"0.00"` strings |
| Escaping strings inconsistently | The hashed string differs from the sent string | Use one shared `JsonString` helper |

## 12. Final Checklist

- [ ] The POS hashes raw compact JSON, not pretty JSON.
- [ ] The string being hashed is exactly the same canonical object TSMS will reconstruct.
- [ ] `transaction.payload_checksum` is generated before root `payload_checksum`.
- [ ] `payload_checksum` is excluded only from the object currently being hashed.
- [ ] `hardware_id` is inside `transaction`.
- [ ] Nested tax objects use `amount` before `tax_type`.
- [ ] Nested adjustment objects use `adjustment_type` before `amount`.
- [ ] Money values are quoted strings with two decimal places.
- [ ] Local TSMS validation returns `Payload is valid.`
