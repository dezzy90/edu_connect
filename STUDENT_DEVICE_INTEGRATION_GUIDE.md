# Student Device Integration Guide

## Overview
This document captures the requirements and technical details for integrating student CRUD operations with biometric device synchronization via MQTT.

---

## MQTT Device Communication Architecture

### Device Connection Requirements

#### Client ID
- **Format**: Device ID/SN (Serial Number)
- **Purpose**: Unique identifier to distinguish between different devices
- **Example**: `1306612`

---

## MQTT Topics Structure

### 1. Device Subscription Topic (Downstream - Server to Device)

**Topic Format**: `mqtt/face/{DEVICE_ID}`

- **Description**: Topic where device receives all downstream data from the server/platform
- **Direction**: Server → Device
- **Example**: `mqtt/face/1306612`
- **Usage**: Send commands, personnel data, configurations to the device

---

### 2. Device Publishing Topics (Upstream - Device to Server)

#### 2.1 Fixed Topics (Cannot be changed in old versions, configurable in new versions)

1. **Application Layer Heartbeat**
   - **Topic**: `mqtt/face/heartbeat`
   - **Purpose**: Device health check and connectivity status

2. **Up/Down Notification**
   - **Topic**: `mqtt/face/basic`
   - **Purpose**: Basic device status notifications (online/offline)

#### 2.2 Dynamic Topics (Based on Device ID)

All dynamic topics follow the pattern: `mqtt/face/{DEVICE_ID}/{SUFFIX}`

1. **Stranger Capture Records**
   - **Topic**: `mqtt/face/{DEVICE_ID}/Snap`
   - **Example**: `mqtt/face/1306612/Snap`
   - **Purpose**: Captures of unrecognized individuals

2. **Identification/Control Records**
   - **Topic**: `mqtt/face/{DEVICE_ID}/Rec`
   - **Example**: `mqtt/face/1306612/Rec`
   - **Purpose**: Recognition and access control records

3. **QR Code Information**
   - **Topic**: `mqtt/face/{DEVICE_ID}/QRCode`
   - **Example**: `mqtt/face/1306612/QRCode`
   - **Purpose**: QR code scan data transmission
   - **Note**: Device must have QR code functionality

4. **Identity Card Information**
   - **Topic**: `mqtt/face/{DEVICE_ID}/IDCard`
   - **Example**: `mqtt/face/1306612/IDCard`
   - **Purpose**: ID card reader data
   - **Note**: Device must have ID card reader functionality

5. **IC/RF Card Number Information**
   - **Topic**: `mqtt/face/{DEVICE_ID}/Card`
   - **Example**: `mqtt/face/1306612/Card`
   - **Purpose**: RFID/IC card data transmission
   - **Note**: Device must have card reader functionality

6. **Door Magnet/Alarm Messages**
   - **Topic**: `mqtt/face/{DEVICE_ID}/Alarm`
   - **Example**: `mqtt/face/1306612/Alarm`
   - **Purpose**: Security alarm notifications
   - **Note**: Device must have alarm functionality

7. **Downlink Data Execution Result (Acknowledgment)**
   - **Topic**: `mqtt/face/{DEVICE_ID}/Ack`
   - **Example**: `mqtt/face/1306612/Ack`
   - **Purpose**: Device acknowledgment of received commands/data
   - **Important**: Used to confirm successful personnel data sync

---

## Device Status Monitoring

### 4.1 Application Layer Heartbeat

#### Description
- Different from underlying connection heartbeat
- Special message type to assist in determining device online status
- **No reply required** from platform
- **Interval**: Approximately 60 seconds (configurable)

#### API Details
| Property | Value |
|----------|-------|
| Interface Name | Application Layer Heartbeat |
| Direction | Device → Platform |
| Uplink Topic | `mqtt/face/heartbeat` |
| Downlink Topic | None (no reply needed) |

#### Message Format

**Reporting Fields:**
```json
{
  "operator": "HeartBeat",
  "info": {
    "facesluiceId": "1306612",
    "time": "2023-01-01 00:00:00"
  }
}
```

**Field Descriptions:**
| Key | Type | Value | Description |
|-----|------|-------|-------------|
| operator | String | "HeartBeat" | Device heartbeat identifier |
| info | Object | - | Concrete content |
| facesluiceId | String | "1306612" | Client ID (Device ID/SN) |
| time | String | "YYYY-MM-DD HH:mm:ss" | Heartbeat timestamp |

---

### 4.2 Online/Offline Notifications

#### 4.2.1 Online Notification

##### Description
- Sent when device connects to MQTT service
- **Platform MUST reply** to online notification
- If platform doesn't reply or replies incorrectly, notification will be resent at intervals

##### API Details
| Property | Value |
|----------|-------|
| Interface Name | Online Notification |
| Direction | Device → Platform |
| Uplink Topic | `mqtt/face/basic` |
| Downlink Topic | `mqtt/face/{DEVICE_ID}` |

##### Device Message Format

**Reporting Fields:**
```json
{
  "operator": "Online",
  "info": {
    "facesluiceId": "1306612",
    "username": "admin",
    "time": "2020-05-12 15:11:10",
    "ip": "172.168.2.202",
    "facesname": "North Gate Entrance"
  }
}
```

**Field Descriptions:**
| Key | Type | Value | Description |
|-----|------|-------|-------------|
| operator | String | "Online" | Online notification identifier |
| info | Object | - | Concrete content |
| facesluiceId | String | "1306612" | Client ID (Device ID/SN) |
| username | String | "admin" | Cloud username |
| time | String | "YYYY-MM-DD HH:mm:ss" | Online timestamp |
| ip | String | "172.168.2.202" | Device IP address |
| facesname | String | "North Gate Entrance" | Device native name |

##### Platform Response Format

**Response Fields:**
```json
{
  "operator": "Online-Ack",
  "info": {
    "facesluiceId": "1305433",
    "result": "ok"
  }
}
```

**Field Descriptions:**
| Key | Type | Value | Description |
|-----|------|-------|-------------|
| operator | String | "Online-Ack" | Platform acknowledgment |
| info | Object | - | Concrete content |
| facesluiceId | String | "1305433" | Client ID (Device ID/SN) |
| result | String | "ok" | Success status |

**Important**: Platform must send this acknowledgment to `mqtt/face/{DEVICE_ID}` topic

---

#### 4.2.2 Offline Notification

##### Description
- Sent when device disconnects from MQTT service
- Caused by connection cancellation or underlying IO errors
- **No reply required** from platform

##### API Details
| Property | Value |
|----------|-------|
| Interface Name | Offline Notification |
| Direction | Device → Platform |
| Uplink Topic | `mqtt/face/basic` |
| Downlink Topic | None (no reply needed) |

##### Message Format

**Reporting Fields:**
```json
{
  "operator": "Offline",
  "info": {
    "facesluiceId": "1305433",
    "time": "2023-01-01 00:00:00"
  }
}
```

**Field Descriptions:**
| Key | Type | Value | Description |
|-----|------|-------|-------------|
| operator | String | "Offline" | Offline notification identifier |
| info | Object | - | Concrete content |
| facesluiceId | String | "1305433" | Client ID (Device ID/SN) |
| time | String | "YYYY-MM-DD HH:mm:ss" | Offline timestamp (may be absent in Will Message) |

---

## Student CRUD Integration Requirements

### Integration Points

1. **Create Student**
   - Save student to database
   - Sync student data to ALL devices in the school via MQTT
   - Use `PersonnelManagementService`

2. **Update Student**
   - Update student in database
   - Update student data on ALL devices in the school via MQTT
   - Use `PersonnelManagementService`

3. **Delete Student**
   - Remove student from database (soft delete)
   - Remove student data from ALL devices in the school via MQTT
   - Use `PersonnelManagementService`

### Service to Use
- **Primary Service**: `PersonnelManagementService`
- **Location**: `app/Services/PersonnelManagementService.php`

---

## Implementation Notes

### Device Targeting
- When a student is added/updated/deleted, the operation must be performed on **ALL biometric devices** belonging to the student's school
- Each device in the school should receive the personnel update via its subscription topic: `mqtt/face/{DEVICE_ID}`

### Acknowledgment Handling
- After sending personnel data to a device, listen for acknowledgment on: `mqtt/face/{DEVICE_ID}/Ack`
- This confirms the device successfully received and processed the data

### Device Status Tracking
- Monitor heartbeat messages on `mqtt/face/heartbeat` to track device online status
- Handle online notifications on `mqtt/face/basic` and send acknowledgments
- Track offline notifications to update device status in database

### Error Handling
- Handle cases where devices are offline
- Implement retry logic for failed synchronizations
- Log all device communication attempts
- Queue personnel updates for offline devices

---

---

## Personnel Management

### 5.1 Single Person Operations

#### 5.1.1 Add or Modify Single Person

##### Description
- Single interface for both adding and modifying personnel
- **Determination Logic**:
  - If `customId` does NOT exist in device → **Add new person**
  - If `customId` EXISTS in device → **Modify existing person**
- **Important**: Due to MQTT queuing and device consumption speed, **must wait for Ack** from previous call before executing next call

##### API Details
| Property | Value |
|----------|-------|
| Interface Name | Individual Personnel Add/Modify |
| Direction | Platform → Device |
| Downlink Topic | `mqtt/face/{DEVICE_ID}` |
| Uplink Topic (Ack) | `mqtt/face/{DEVICE_ID}/Ack` |

##### Platform Message Format

**Required Fields:**

| Key | Type | Values | Description |
|-----|------|--------|-------------|
| operator | String | "EditPerson" | Operation identifier |
| messageId | String | Unique ID | Platform-generated message ID to distinguish each message |
| info | Object | - | Personnel information container |

**Personnel Information Fields (info object):**

| Key | Type | Values | Required | Description |
|-----|------|--------|----------|-------------|
| customId | String | Max 48 chars | ✅ Yes | Platform-generated unique ID (recommended: ID card number) |
| name | String | Max 32 chars | ❌ Optional | Person's name |
| personType | int | 0-1 | ✅ Yes | 0: Whitelist, 1: Blacklist |
| tempCardType | int | 0-4 | ✅ Yes | List type (see below) |
| cardValidBegin | String | "YYYY-MM-DD HH:mm:ss" | ⚠️ Conditional | Start time (required for temp types 1,2,4) |
| cardValidEnd | String | "YYYY-MM-DD HH:mm:ss" | ⚠️ Conditional | End time (required for temp types 1,2,4) |
| EffectNumber | int | Number | ⚠️ Conditional | Valid count (required for temp types 3,4) |
| nation | int | 1-57 | ❌ Optional | Nationality (1=Han Chinese, see Appendix A) |
| gender | int | 0-1 | ❌ Optional | 0: Male, 1: Female |
| idCard | String | Max 32 chars | ❌ Optional | ID card number |
| birthday | String | "YYYY-MM-DD" | ❌ Optional | Date of birth |
| telnum1 | String | Max 32 chars | ❌ Optional | Phone number |
| native | String | Max 32 chars | ❌ Optional | Place of origin |
| address | String | Max 72 chars | ❌ Optional | Address |
| notes | String | Max 64 chars | ❌ Optional | Remarks |
| cardType | int | 0 | ❌ Optional | Document type: 0=ID card |
| cardType2 | int | 0-3 | ❌ Optional | Wiegand card number generation method |
| CardMode | unsigned int | 0-1 | ❌ Optional | Card number composition mode (0=Decimal, 1=Hex) |
| WiegandType | int | 0,1,6,7 | ⚠️ Conditional | Wiegand protocol (required if cardType2=2) |
| cardNum2 | int | Number | ⚠️ Conditional | Wiegand card number (required if cardType2=2) |
| WGFacilityCode | int | Number | ⚠️ Conditional | Facility code (required if WiegandType=6 or 7) |
| RFCardMode | unsigned int | 0-1 | ❌ Optional | RF card mode (0=Decimal, 1=Hex, default=1) |
| RFIDCard | String | Max 10 chars | ❌ Optional | ID card number for built-in reader |
| pic | String | Base64 | ⚠️ Either pic or picURI | Person photo (base64, max 1MB) |
| picURI | String | URI | ⚠️ Either pic or picURI | Person photo URI (requires correct DNS) |
| strategyInfo | String | - | ❌ Optional | Access strategy information keyword |
| strategyData | String | JSON array | ⚠️ Conditional | Strategy data (required if strategyInfo not empty) |
| strategyNum | int | Number | ⚠️ Conditional | Number of strategies (required if strategyData not empty) |
| strategyID | String | ID | ⚠️ Conditional | Strategy ID (required if strategyData not empty) |
| strategyName | String | Max 64 chars | ❌ Optional | Strategy name (reserved) |

**Temporary List Types (tempCardType):**
- **0**: Permanent list
- **1**: Temporary list 1 (valid for time period)
- **2**: Temporary list 2 (valid for same time period daily)
- **3**: Temporary list 3 (valid for number of times)
- **4**: Temporary list 4 (valid for same time period daily)

**Note**: Invalidated temporary lists are automatically deleted after next recognition or after 24 hours.

##### Example Message (Using Base64 Image)

```json
{
  "messageId": "ID:localhost-637050272518414388:79346:87:5",
  "operator": "EditPerson",
  "info": {
    "customId": "063c81e0fce184c696cdb7e049230f5e",
    "name": "Zhang San",
    "nation": 1,
    "gender": 0,
    "birthday": "1995-06-12",
    "address": "",
    "idCard": "421381199504030001",
    "tempCardType": 0,
    "EffectNumber": 3,
    "cardValidBegin": "2019-10-10 10:00:00",
    "cardValidEnd": "2019-10-10 16:00:00",
    "telnum1": "1888888888888",
    "native": "Shenzhen, Guangdong",
    "cardType2": 0,
    "cardNum2": "",
    "notes": "",
    "personType": 0,
    "cardType": 0,
    "strategyInfo": {
      "strategyNum": 1,
      "strategyData": [
        {
          "strategyID": 1,
          "strategyName": "test1"
        }
      ]
    },
    "pic": "data:image/jpeg;base64,Qk025wAAAAAAAAAgXGB........"
  }
}
```

**Important Notes:**
- Image must be base64 encoded and not exceed 1MB
- Either `pic` (base64) or `picURI` (URI) must be provided when adding
- When modifying without changing photo, neither `pic` nor `picURI` is required
- `customId` is the unique identifier - use student ID or biometric ID
- Must wait for device Ack before sending next personnel update

##### Example Message (Using URI)

```json
{
  "messageId": "ID:localhost-637050272518414388:79346:87:5",
  "operator": "EditPerson",
  "info": {
    "customId": "063c81e0fce184c696cdb7e049230f5e",
    "name": "Zhang San",
    "nation": 1,
    "gender": 0,
    "birthday": "1995-06-12",
    "address": "",
    "idCard": "421381199504030001",
    "tempCardType": 0,
    "EffectNumber": 3,
    "cardValidBegin": "2019-10-10 10:00:00",
    "cardValidEnd": "2020-10-10 16:00:00",
    "telnum1": "1888888888888",
    "native": "Shenzhen, Guangdong",
    "cardType2": 0,
    "cardNum2": "",
    "notes": "",
    "personType": 0,
    "cardType": 0,
    "strategyInfo": {
      "strategyNum": 1,
      "strategyData": [
        {
          "strategyID": 1,
          "strategyName": "test1"
        }
      ]
    },
    "picURI": "https://btgoss.oss-cn-beijing.aliyuncs.com/image/xxx.jpg"
  }
}
```

##### Device Acknowledgment Response

**Response Fields:**

| Key | Type | Values | Description |
|-----|------|--------|-------------|
| operator | String | "EditPerson-Ack" | Acknowledgment identifier |
| messageId | String | Same as request | Message ID from original request |
| code | int | 200 = Success | Error code (see error code table) |
| info | Object | - | Response content |
| facesluiceId | String | "1306612" | Device ID/SN |
| personId | String | "3" | Device database primary key (optional) |
| customId | String | Same as request | Platform-issued unique ID |
| result | String | "ok" | Success status |
| detail | String | Error reason | Failure reason (optional, on error) |

**Example Response:**

```json
{
  "messageId": "ID:localhost-637050272518414388:79346:87:5",
  "operator": "EditPerson-Ack",
  "code": "200",
  "info": {
    "facesluiceId": "1306612",
    "personId": "3",
    "customId": "063c81e0fce184c696cdb7e049230f5e",
    "result": "ok"
  }
}
```

---

#### 5.1.2 Batch Personnel Operations

##### Overview
- **Purpose**: Reduce device-server reconnection issues caused by slow device consumption
- **Use Case**: Full (high volume) personnel updates
- **Mutual Exclusivity**: Batch operations are mutually exclusive
- **Progress Monitoring**: Poll `QueryProgress` interface to check completion
- **Image Support**: Only URI method supported (no base64)

##### Important Restrictions
- Cannot call other add/modify/delete commands during batch processing
- Must wait for batch completion before issuing next batch operation
- Use `QueryProgress` to determine when batch operation is complete

---

#### 5.1.2.1 Batch Add or Modify Personnel

##### Description
- **Maximum**: 1,000 personnel per batch
- **Logic**: Same as single operation - `customId` determines add vs modify
- **Response**: Returns success/failure counts and details immediately
- **Error Codes**: Failed operations include error codes (see error code table)

##### API Details
| Property | Value |
|----------|-------|
| Interface Name | Batch Add/Modify Personnel |
| Direction | Platform → Device |
| Downlink Topic | `mqtt/face/{DEVICE_ID}` |
| Uplink Topic (Ack) | `mqtt/face/{DEVICE_ID}/Ack` |

##### Platform Message Format

**Required Fields:**

| Key | Type | Values | Description |
|-----|------|--------|-------------|
| operator | String | "EditPersonsNew" | Batch operation identifier |
| messageId | String | Unique ID | Platform-generated message ID |
| DataBegin | String | "BeginFlag" | Packet start marker (integrity check) |
| PersonNum | int | 1-1000 | Number of personnel (must match array length) |
| info | Array | Personnel list | Array of personnel objects |
| DataEnd | String | "EndFlag" | Packet end marker (integrity check) |

**Personnel Object Fields (same as single operation):**
- All fields from single `EditPerson` operation apply
- `picURI` is **required** for new personnel (URI only, no base64)
- `isCheckSimilarity` (optional): Detect image similarity (0=don't detect, 1=detect)

##### Example Message

```json
{
  "messageId": "EditPersonsNewlist2020-07-24T19:07:00_00002",
  "DataBegin": "BeginFlag",
  "operator": "EditPersonsNew",
  "PersonNum": "1000",
  "info": [
    {
      "customId": "063c81e0fce184c696cdb7e049230f5e23dfqwxc230000",
      "name": "modify000",
      "telnum1": "13700880000"
    },
    {
      "customId": "063c81e0fce184c696cdb7e049230f5e23dfqwxc230999",
      "name": "modify999",
      "picURI": "https://btgongpluss.oss-cn-beijing.aliyuncs.com/bigheadphoto/xxx111.jpg"
    }
  ],
  "DataEnd": "EndFlag"
}
```

##### Device Acknowledgment Response

**Response Fields:**

| Key | Type | Values | Description |
|-----|------|--------|-------------|
| operator | String | "EditPersonsNew-Ack" | Batch acknowledgment |
| messageId | String | Same as request | Message ID from request |
| code | int | 200 = Success | Error code |
| info | Object | - | Response content |
| facesluiceId | String | "1306612" | Device ID/SN |
| AddErrNum | int | 0-1000 | Number of failures |
| AddSucNum | int | 0-1000 | Number of successes |
| AddErrInfo | Array | Error details | Array of {customId, errcode} |
| AddSucInfo | Array | Success details | Array of {customId} |
| result | String | "ok"/"fail" | Overall result |
| detail | String | Error reason | Failure reason (optional) |

**Example Response:**

```json
{
  "messageId": "EditPersonsNewlist2020-07-24T19:07:00_00002",
  "operator": "EditPersonsNew-Ack",
  "info": {
    "facesluiceId": "1306612",
    "AddErrNum": "1",
    "AddSucNum": "999",
    "AddErrInfo": [
      {
        "customId": "063c81e0fce184c696cdb7e049230f5e23dfqwxc230898",
        "errcode": "461"
      }
    ],
    "AddSucInfo": [
      {
        "customId": "063c81e0fce184c696cdb7e049230f5e23dfqwxc230000"
      },
      {
        "customId": "063c81e0fce184c696cdb7e049230f5e23dfqwxc230999"
      }
    ],
    "result": "ok"
  }
}
```

---

#### 5.1.2.2 Query Batch Operation Progress

##### Description
- **Purpose**: Check if device is currently processing batch operations
- **Use Case**: Coordinate task processing before issuing new batch operations
- **Status Types**: None, AddPersons, EditPersons, EditPersonsNew

##### API Details
| Property | Value |
|----------|-------|
| Interface Name | Query Progress |
| Direction | Platform → Device |
| Downlink Topic | `mqtt/face/{DEVICE_ID}` |
| Uplink Topic (Ack) | `mqtt/face/{DEVICE_ID}/Ack` |

##### Platform Message Format

```json
{
  "messageId": "ID:localhost-637050272518414388:79346:87:1",
  "operator": "QueryProgress",
  "info": {}
}
```

##### Device Response

**Response Fields:**

| Key | Type | Values | Description |
|-----|------|--------|-------------|
| operator | String | "QueryProgress-Ack" | Progress query response |
| messageId | String | Same as request | Message ID |
| code | int | 200 = Success | Error code |
| info | Object | - | Response content |
| facesluiceId | String | "2864575" | Device ID/SN |
| QueryType | String | Interface name | Current batch task type |
| Status | int | 0-3 | Device status (see below) |
| result | String | "ok"/"fail" | Query result |
| detail | String | Error reason | Failure reason (optional) |

**Status Values:**
- **0**: Idle (no batch tasks)
- **1**: Processing batch add personnel task
- **2**: Processing batch modify personnel task
- **3**: Processing batch add/modify personnel task

**Example Response:**

```json
{
  "messageId": "ID:localhost-637050272518414388:79346:87:1",
  "operator": "QueryProgress-Ack",
  "code": "200",
  "info": {
    "facesluiceId": "2864575",
    "QueryType": "EditPersonsNew",
    "Status": "3",
    "result": "ok"
  }
}
```

---

---

### 5.1.3 Personnel Deletion Operations

#### 5.1.3.1 Delete Single Person

##### Description
- Deletes personnel based on `customId`
- For large-scale deletions, use batch delete interface instead
- Must wait for previous command to complete before deleting next person

##### API Details
| Property | Value |
|----------|-------|
| Interface Name | Delete Single Person |
| Direction | Platform → Device |
| Downlink Topic | `mqtt/face/{DEVICE_ID}` |
| Uplink Topic (Ack) | `mqtt/face/{DEVICE_ID}/Ack` |

##### Platform Message Format

**Required Fields:**

| Key | Type | Values | Description |
|-----|------|--------|-------------|
| operator | String | "DelPerson" | Delete operation identifier |
| messageId | String | Unique ID | Platform-generated message ID |
| info | Object | - | Deletion information container |
| customId | String | Max 48 chars | Unique ID of person to delete |

**Example Message:**

```json
{
  "operator": "DelPerson",
  "messageId": "ID:localhost-637046811507388956:23952:65:48",
  "info": {
    "customId": "063c81e0fce184c696cdb7e049230f5e"
  }
}
```

##### Device Acknowledgment Response

**Response Fields:**

| Key | Type | Values | Description |
|-----|------|--------|-------------|
| operator | String | "DelPerson-Ack" | Delete acknowledgment |
| messageId | String | Same as request | Message ID |
| code | int | 200 = Success | Error code |
| info | Object | - | Response content |
| facesluiceId | String | "1306612" | Device ID/SN |
| personId | String | "3" | Device database primary key |
| customId | String | Same as request | Deleted person's ID |
| result | String | "ok"/"fail" | Deletion result |
| detail | String | Error reason | Failure reason (optional) |

**Example Response:**

```json
{
  "messageId": "ID:localhost-637046811507388956:23952:65:48",
  "operator": "DelPerson-Ack",
  "code": "200",
  "info": {
    "facesluiceId": "1306612",
    "personId": "3",
    "customId": "063c81e0fce184c696cdb7e049230f5e",
    "result": "ok"
  }
}
```

---

#### 5.1.3.2 Batch Delete Personnel

##### Description
- **Maximum**: 200 personnel per batch
- **Processing Time**: Usually takes ~7 seconds to complete
- **Behavior**: 
  - Deletes registration images of corresponding control records
  - Does NOT delete the control records themselves
  - Non-existent `customId` will be reported in failure (can be ignored)
- **Wait Required**: Must wait for command to return before issuing next command

##### API Details
| Property | Value |
|----------|-------|
| Interface Name | Batch Delete Personnel |
| Direction | Platform → Device |
| Downlink Topic | `mqtt/face/{DEVICE_ID}` |
| Uplink Topic (Ack) | `mqtt/face/{DEVICE_ID}/Ack` |

##### Platform Message Format

**Required Fields:**

| Key | Type | Values | Description |
|-----|------|--------|-------------|
| operator | String | "DeletePersons" | Batch delete identifier |
| messageId | String | Unique ID | Platform-generated message ID |
| DataBegin | String | "BeginFlag" | Packet start marker |
| PersonNum | int | 0-200 | Number of personnel to delete |
| info | Object | - | Deletion information |
| customId | Array | String array | Array of customIds to delete |
| DataEnd | String | "EndFlag" | Packet end marker |

**Example Message:**

```json
{
  "messageId": "2020-05-14 11:07:00",
  "DataBegin": "BeginFlag",
  "operator": "DeletePersons",
  "PersonNum": "200",
  "info": {
    "customId": [
      "063c81e0fce184c696cdb7e049230f5e23dfqwxc230000",
      "063c81e0fce184c696cdb7e049230f5e23dfqwxc230199"
    ]
  },
  "DataEnd": "EndFlag"
}
```

##### Device Acknowledgment Response

**Response Fields:**

| Key | Type | Values | Description |
|-----|------|--------|-------------|
| operator | String | "DeletePersons-Ack" | Batch delete acknowledgment |
| messageId | String | Same as request | Message ID |
| code | int | 200 = Success | Error code |
| info | Object | - | Response content |
| facesluiceId | String | "1306612" | Device ID/SN |
| DelErrNum | int | 0-200 | Number of failures (includes non-existent) |
| DelSucNum | int | 0-200 | Number of successes |
| DelErrInfo | Array | Error details | Array of {customId} for failures |
| DelSucInfo | Array | Success details | Array of {customId} for successes |
| result | String | "ok"/"fail" | Overall result |
| detail | String | Error reason | Failure reason (optional) |

**Example Response:**

```json
{
  "messageId": "2020-05-14 11:07:00 DeletePersons",
  "operator": "DeletePersons-Ack",
  "code": "200",
  "info": {
    "facesluiceId": "1306612",
    "DelErrNum": "1",
    "DelSucNum": "199",
    "DelErrInfo": [
      {
        "customId": "063c81e0fce184c696cdb7e049230f5e23dfqwxc230199"
      }
    ],
    "DelSucInfo": [
      {
        "customId": "063c81e0fce184c696cdb7e049230f5e23dfqwxc230000"
      },
      {
        "customId": "063c81e0fce184c696cdb7e049230f5e23dfqwxc230198"
      }
    ],
    "result": "ok"
  }
}
```

---

#### 5.1.3.3 Delete All Personnel

##### Description
- **WARNING**: Deletes ALL personnel lists from the device
- **Destructive**: Also deletes control records - **CANNOT BE RECOVERED**
- **Auto-Restart**: Device will automatically restart after successful deletion
- **Use with Caution**: This is a dangerous operation

##### API Details
| Property | Value |
|----------|-------|
| Interface Name | Delete All Personnel |
| Direction | Platform → Device |
| Downlink Topic | `mqtt/face/{DEVICE_ID}` |
| Uplink Topic (Ack) | `mqtt/face/{DEVICE_ID}/Ack` |

##### Platform Message Format

**Required Fields:**

| Key | Type | Values | Description |
|-----|------|--------|-------------|
| operator | String | "DeleteAllPerson" | Delete all identifier |
| messageId | String | Unique ID | Platform-generated message ID |
| info | Object | - | Deletion confirmation |
| deleteall | int | 0-1 | 1 = Confirm deletion |

**Example Message:**

```json
{
  "messageId": "ID:localhost-637046811507388956:23952:65:48",
  "operator": "DeleteAllPerson",
  "info": {
    "deleteall": "1"
  }
}
```

##### Device Acknowledgment Response

**Response Fields:**

| Key | Type | Values | Description |
|-----|------|--------|-------------|
| operator | String | "DeleteAllPerson-Ack" | Delete all acknowledgment |
| messageId | String | Same as request | Message ID |
| code | int | 200 = Success | Error code |
| info | Object | - | Response content |
| facesluiceId | String | "1306612" | Device ID/SN |
| result | String | "ok"/"fail" | Deletion result |
| detail | String | Error reason | Failure reason (optional) |

**Example Response:**

```json
{
  "messageId": "ID:localhost-637046811507388956:23952:65:48",
  "operator": "DeleteAllPerson-Ack",
  "code": "200",
  "info": {
    "facesluiceId": "1306612",
    "result": "ok"
  }
}
```

**Important Notes:**
- Device will restart automatically after successful deletion
- All personnel data and control records will be permanently deleted
- This operation cannot be undone
- Use only when absolutely necessary (e.g., device reset, school closure)

---

---

### 5.1.4 Personnel Query Operations

#### 5.1.4.1 Query All Personnel CustomIds

##### Description
- Returns all `customId` values from the device
- **Excludes** personnel added from device web backend (they don't have customId)
- Only returns platform-managed personnel with customId

##### API Details
| Property | Value |
|----------|-------|
| Interface Name | Query All Personnel CustomId |
| Direction | Platform → Device |
| Downlink Topic | `mqtt/face/{DEVICE_ID}` |
| Uplink Topic (Ack) | `mqtt/face/{DEVICE_ID}/Ack` |

##### Platform Message Format

```json
{
  "operator": "QueryPerson",
  "messageId": "ID:localhost-637046811507388956:23952:65:48",
  "info": {}
}
```

##### Device Response

**Response Fields:**

| Key | Type | Values | Description |
|-----|------|--------|-------------|
| operator | String | "QueryPerson-Ack" | Query response |
| messageId | String | Same as request | Message ID |
| code | int | 200 = Success | Error code |
| info | Object | - | Response content |
| facesluiceId | String | "1306612" | Device ID/SN |
| TotalPersonNum | int | Number | Total personnel (including those without customId) |
| QueryPersonNum | int | Number | Personnel with customId |
| customId | String | Comma-separated | All customIds as comma-separated string |
| result | String | "ok"/"fail" | Query result |
| detail | String | Error reason | Failure reason (optional) |

**Example Response:**

```json
{
  "messageId": "ID:localhost-637046811507388956:23952:65:48",
  "operator": "QueryPerson-Ack",
  "code": "200",
  "info": {
    "facesluiceId": "1306612",
    "TotalPersonNum": "99",
    "QueryPersonNum": "98",
    "customId": "063c81e0fce184c696cdb7e049230f5e23dfqwxc230000,063c81e0fce184c696cdb7e049230f5e23dfqwxc230001,063c81e0fce184c696cdb7e049230f5e23dfqwxc230097",
    "result": "ok"
  }
}
```

---

#### 5.1.4.2 Query Single Personnel Information

##### Description
- Returns detailed information for a specific person based on `customId`
- Optionally includes person's photo (base64)

##### API Details
| Property | Value |
|----------|-------|
| Interface Name | Query Single Personnel Information |
| Direction | Platform → Device |
| Downlink Topic | `mqtt/face/{DEVICE_ID}` |
| Uplink Topic (Ack) | `mqtt/face/{DEVICE_ID}/Ack` |

##### Platform Message Format

**Required Fields:**

| Key | Type | Values | Description |
|-----|------|--------|-------------|
| operator | String | "SearchPerson" | Query operation |
| messageId | String | Unique ID | Message ID |
| info | Object | - | Query parameters |
| customId | String | Max 48 chars | Person's unique ID |
| Picture | int | 0-1 (default 0) | 0=No image, 1=Include image |

**Example Message:**

```json
{
  "messageId": "ID:localhost-637046811507388956:23952:65:48",
  "operator": "SearchPerson",
  "info": {
    "customId": "063c81e0fce184c696cdb7e049230f5e",
    "Picture": "1"
  }
}
```

##### Device Response

Returns complete personnel information including all fields from EditPerson operation plus:
- `personId`: Device database primary key
- `creatTime`: Personnel creation timestamp
- `pic`: Base64 image (if Picture=1)

**Example Response:**

```json
{
  "messageId": "ID:localhost-637046811507388956:23952:65:48",
  "operator": "SearchPerson-Ack",
  "code": "200",
  "info": {
    "facesluiceId": "1306612",
    "personId": "1",
    "customId": "063c81e0fce184c696cdb7e049230f5e",
    "name": "Test",
    "gender": "0",
    "idCard": "421381199504030001",
    "birthday": "1995-06-12",
    "address": "XXX, Bao'an District, Shenzhen, Guangdong Province, China",
    "creatTime": "2023-01-01T00:00:00",
    "telnum1": "1888888888888",
    "personType": "0",
    "nation": "1",
    "native": "Shenzhen, Guangdong",
    "notes": "Remarks",
    "strategyNum": 2,
    "strategyID": [1, 2],
    "cardType2": "0",
    "cardNum2": "1",
    "CardMode": "0",
    "tempCardType": "0",
    "EffectNumber": "0",
    "cardValidBegin": "0000-00-00 00:00:00",
    "cardValidEnd": "0000-00-00 00:00:00",
    "result": "ok"
  },
  "pic": "data:image/jpeg;base64,Qk025wAAAAAAAAADYAAAZ..."
}
```

---

#### 5.1.4.3 Query Multiple Personnel Information

##### Description
- Search personnel using multiple criteria
- **Does NOT support** returning images
- **Maximum**: 2000 results per query

##### Search Methods

1. **Search by Time Range**: Set BeginTime and EndTime, leave name empty
2. **Search by Name**: Set name (fuzzy search), leave time range empty
3. **Get All**: Leave all optional fields empty

##### API Details
| Property | Value |
|----------|-------|
| Interface Name | Query Multiple Personnel Information |
| Direction | Platform → Device |
| Downlink Topic | `mqtt/face/{DEVICE_ID}` |
| Uplink Topic (Ack) | `mqtt/face/{DEVICE_ID}/Ack` |

##### Platform Message Format

**Required Fields:**

| Key | Type | Values | Description |
|-----|------|--------|-------------|
| operator | String | "SearchPersonList" | Multi-query operation |
| messageId | String | Unique ID | Message ID |
| info | Object | - | Query parameters |

**Optional Query Parameters:**

| Key | Type | Values | Description |
|-----|------|--------|-------------|
| personType | int | 0-2 (default 2) | 0=Whitelist, 1=Blacklist, 2=All |
| BeginTime | String | "YYYY-MM-DDTHH:mm:ss" | Search start time |
| EndTime | String | "YYYY-MM-DDTHH:mm:ss" | Search end time |
| gender | int | 0-2 | 0=Male, 1=Female, 2=All |
| cardNum2 | unsigned int | Number | Access card number |
| name | String | Name | Person's name (fuzzy search) |
| BeginNO | int | Default 0 | Starting position in results |
| RequestCount | int | 1-2000 (default 1000) | Max results to return |

**Example Messages:**

**1. Search by Time Range:**
```json
{
  "messageId": "ID:localhost-637046811507388956:23952:65:48",
  "operator": "SearchPersonList",
  "info": {
    "facesluiceId": "1306612",
    "personType": 0,
    "BeginTime": "2018-08-13T00:00:00",
    "EndTime": "2021-08-19T23:59:59",
    "gender": 2,
    "BeginNO": 0,
    "RequestCount": 100
  }
}
```

**2. Fuzzy Search by Name:**
```json
{
  "messageId": "ID:localhost-637046811507388956:23952:65:48",
  "operator": "SearchPersonList",
  "info": {
    "facesluiceId": "1306612",
    "personType": 0,
    "name": "xxx",
    "gender": 2,
    "BeginNO": 0,
    "RequestCount": 100
  }
}
```

**3. Get All Personnel:**
```json
{
  "messageId": "ID:localhost-637046811507388956:23952:65:48",
  "operator": "SearchPersonList",
  "info": {
    "facesluiceId": "1306612"
  }
}
```

##### Device Response

**Response Fields:**

| Key | Type | Values | Description |
|-----|------|--------|-------------|
| operator | String | "SearchPersonList-Ack" | Multi-query response |
| messageId | String | Same as request | Message ID |
| code | int | 200 = Success | Error code |
| info | Object | - | Response content |
| facesluiceId | String | "1306612" | Device ID/SN |
| TotalPersonNum | int | Number | Total matching personnel |
| PersonNum | int | Number | Personnel returned in this response |
| List | Array | Personnel objects | Array of matching personnel |
| result | String | "fail" | Only present on failure |
| detail | String | Error reason | Failure reason (optional) |

**List Object Fields:** Contains all personnel fields (name, gender, customId, etc.)

**Example Response:**

```json
{
  "messageId": "ID:localhost-637046811507388956:23952:65:48",
  "operator": "SearchPersonList-Ack",
  "code": 200,
  "info": {
    "facesluiceId": "1306612",
    "TotalPersonNum": 2,
    "PersonNum": 2,
    "List": [
      {
        "LibID": 2,
        "personType": 0,
        "name": "xxx",
        "gender": 0,
        "nation": 1,
        "cardType": 0,
        "idCard": "421381199504030001",
        "birthday": "1995-06-12",
        "telnum1": "1888888888888",
        "native": "sz",
        "address": " ",
        "notes": " ",
        "cardType2": 0,
        "strategyNum": 2,
        "strategyID": [1, 2],
        "cardNum2": 1,
        "tempCardType": 0,
        "customId": "210623022466",
        "cardValidBegin": "0000-00-00 00:00:00",
        "cardValidEnd": "0000-00-00 00:00:00"
      }
    ]
  }
}
```

---

## Summary of Personnel Operations

### Single Operations
| Operation | Operator | Max Wait | Use Case |
|-----------|----------|----------|----------|
| Add/Modify | EditPerson | Must wait for Ack | Individual student updates |
| Delete | DelPerson | Must wait for Ack | Individual student removal |

### Batch Operations
| Operation | Operator | Max Count | Processing Time | Use Case |
|-----------|----------|-----------|-----------------|----------|
| Add/Modify | EditPersonsNew | 1,000 | Varies | Full roster updates |
| Delete | DeletePersons | 200 | ~7 seconds | Mass student removal |
| Delete All | DeleteAllPerson | All | Device restarts | Complete reset |

### Progress Monitoring
| Operation | Operator | Purpose |
|-----------|----------|---------|
| Query Progress | QueryProgress | Check batch operation status |

---

**Status**: Information Collection Phase - Personnel Deletion Added
**Last Updated**: 2025-09-30
