# Device Error Codes Reference

## Common Success Code
- **200**: Operation successful

---

## Personnel Operation Error Codes

### Single Person Add/Modify Errors (460-476)
| Code | Description |
|------|-------------|
| 460 | Data out of range (exceeds 1M) |
| 461 | Can't find customId |
| 462 | Parameter error (missing "info") |
| 463 | Base64 decode error for pic |
| 464 | Failed to extract facial features from image |
| 465 | Database operation failed |
| 467 | Personnel database is full |
| 468 | URI image data too short (<1000 bytes) |
| 469 | URI image data too long (>1M, limit 1080P) |
| 470 | Failed to parse image server address |
| 471 | URI download timeout/failure (check DNS) |
| 472 | Neither "pic" nor "picURI" provided |
| 473 | RFIDCard number already exists (built-in card reader) |
| 474 | Failed to get WGFacilityCode keyword |
| 475 | Failed to get cardNum2 keyword |
| 476 | cardNum2 (Wiegand card number) already exists |

---

### Batch Operation Command Errors (410-418)
| Code | Description |
|------|-------------|
| 410 | Batch operation is busy (previous command not completed) |
| 411 | Batch operation not ready (previous command not completed) |
| 412 | Can't find DataBegin keyword |
| 413 | Can't find DataEnd keyword |
| 414 | Parameter error (missing "info") |
| 415 | Can't find PersonNum keyword |
| 416 | PersonNum out of range (max 1000 for add/modify, 200 for delete) |
| 417 | JSON array count doesn't match PersonNum value |
| 418 | Can't find info keyword |
| 460 | Single packet data exceeds 1M |

---

### Batch Add/Modify Personnel Item Errors (errcode 461-482)
| Code | Description |
|------|-------------|
| 461 | customId already exists |
| 462 | Reserved |
| 463 | Reserved / Failed to get picURI keyword |
| 464 | Failed to parse image server address |
| 465 | URI download timeout/failure (check DNS) |
| 466 | Failed to get URI image data content |
| 467 | Image data too large (>1M, limit 1080P) |
| 468 | Failed to extract facial features from image |
| 469 | Failed to write image data to database |
| 470 | Failed to write personnel data to database |
| 471 | Failed to search database writable locations |
| 472 | Failed to overwrite disk |
| 473 | Failed to modify database personnel record |
| 474 | Device personnel database is full |
| 475 | Wiegand card number already exists |
| 476 | Failed to get WGFacilityCode keyword |
| 477 | Failed to get cardNum2 keyword |
| 478 | Excessive similarity between images (duplicate person) |
| 479 | Failed to get WiegandType keyword |
| 480 | RFIDCard number already exists (built-in card reader) |
| 481 | Failed to get picURI keyword |
| 482 | Failed to read existing person's picture |

---

### Delete Personnel Errors

#### Single Delete (461-464)
| Code | Description |
|------|-------------|
| 461 | Can't find customId keyword |
| 462 | Parameter error (missing "info") |
| 464 | Can't find person with this customId |

#### Batch Delete Command (412-418)
| Code | Description |
|------|-------------|
| 412 | Can't find DataBegin keyword |
| 413 | Can't find DataEnd keyword |
| 414 | Parameter error (missing "info") |
| 415 | Can't find PersonNum keyword |
| 416 | PersonNum out of range (max 200) |
| 417 | JSON array count doesn't match PersonNum |
| 418 | Can't find customId keyword |

#### Delete All Personnel (462-463)
| Code | Description |
|------|-------------|
| 462 | Parameter error (missing "info") |
| 463 | Parameter deleteall error (missing "deleteall") |

---

### Query Personnel Errors

#### Query All CustomIds - No specific errors documented

#### Query Single Person (462-464)
| Code | Description |
|------|-------------|
| 462 | Parameter error (missing "info") |
| 463 | Parameter customId error (missing "customId") |
| 464 | Can't find person with this customId |

#### Query Multiple Personnel (462, 465-466)
| Code | Description |
|------|-------------|
| 462 | Parameter error (missing "info") |
| 465 | Unknown facesluiceId (device ID mismatch) |
| 466 | Can't find any matching personnel |

---

### Progress Query Errors (461)
| Code | Description |
|------|-------------|
| 461 | Can't find info keyword |

---

## Other Device Operation Error Codes

### Advertisement Operations (461-467)
| Code | Description |
|------|-------------|
| 461 | Parameter error (missing "info") |
| 462 | Can't find adslot keyword |
| 463 | adslot value out of range |
| 464 | Image download path error |
| 465 | Advertisement file download failed |
| 466 | Unknown picture type |
| 467 | Unknown facesluiceId |

### QR Code Operations (462-465)
| Code | Description |
|------|-------------|
| 462 | Parameter error (missing "info") |
| 463 | Can't find QRCodeData or size limit exceeded |
| 464 | Unknown facesluiceId |
| 465 | Version doesn't support this interface |

### Scene Capture Operations (462-465)
| Code | Description |
|------|-------------|
| 462 | Parameter error (missing "info") |
| 463 | GetSceneSnap error |
| 464 | Version doesn't support GetSceneSnap |
| 465 | Unknown facesluiceId |

### Image Comparison Operations (462-469)
| Code | Description |
|------|-------------|
| 462 | Parameter error (missing "info") |
| 463 | Unknown facesluiceId |
| 464 | Unknown pic1info |
| 465 | Unknown pic2info |
| 466 | Base64 decode pic1info error |
| 467 | Failed to get facial features from pic1info |
| 468 | Base64 decode pic2info error |
| 469 | Failed to get facial features from pic2info |

### Face Database Search (460-468)
| Code | Description |
|------|-------------|
| 460 | Data out of range (exceeds 1M) |
| 461 | Unknown facesluiceId |
| 462 | Parameter error (missing "info") |
| 463 | Unknown MaxSimilarity |
| 464 | Unknown MaxNum |
| 465 | Unknown picinfo |
| 466 | No personnel meet conditions |
| 467 | Failed to get facial features from pic1info |
| 468 | Base64 decode picinfo error |

### Face Detection (462-465)
| Code | Description |
|------|-------------|
| 462 | Parameter error (missing "info") |
| 463 | Unknown facesluiceId |
| 464 | Unknown picinfo |
| 465 | Base64 decode picinfo error |

---

## Error Handling Best Practices

### For Students CRUD Implementation:

1. **Always check code 200** for success
2. **Handle common errors**:
   - 461: customId issues (already exists or not found)
   - 464: Facial feature extraction failed (bad image quality)
   - 467/474: Database full
   - 471: Network/DNS issues
   - 478: Duplicate person detected

3. **Batch operation handling**:
   - Check command-level errors (410-418) first
   - Then check individual item errors (errcode in response)
   - Log all failures for retry

4. **Retry logic**:
   - Retry on network errors (471)
   - Don't retry on validation errors (461, 464, 478)
   - Implement exponential backoff

5. **User feedback**:
   - Provide clear error messages based on error codes
   - Suggest solutions (e.g., "Image quality too low" for 464)

---

**Last Updated**: 2025-09-30
