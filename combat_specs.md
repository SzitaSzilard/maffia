# NetMafia Combat Mechanics Notes

## Maximum Item Stats Calculation (as of Dec 30, 2025)

This calculation represents the maximum possible Attack and Defense values a player can achieve purely from items (Best Weapon + All Defensive Items).

**Scenario:**
- **Weapon:** Minigun Extra Limited (Best Weapon)
- **Armor:** All 12 types of defensive items equipped simultaneously (Stacking allowed for different types)

### Breakdown

| Item Type | Item Name | Attack | Defense |
|:---|:---|---:|---:|
| **Weapon** | **Minigun Extra Limited** | **150** | **80** |
| | | | |
| **Armor** | Dragon Golyóálló Mellény | 5 | 12 |
| **Armor** | Taktikai pajzs | 12 | 30 |
| **Armor** | Golyóálló ruha | 15 | 50 |
| **Armor** | Pajzs | 4 | 15 |
| **Armor** | Golyóálló maszk | 3 | 9 |
| **Armor** | Pitbull kutya | 7 | 8 |
| **Armor** | Rottweiler kutya | 4 | 6 |
| **Armor** | Golyóálló mellény | 3 | 6 |
| **Armor** | US Army Sisak | 1 | 5 |
| **Armor** | Kevlár Kesztyű | 3 | 4 |
| **Armor** | Sisak | 1 | 3 |
| **Armor** | Doberman kutya | 1 | 2 |
| | **Armor Subtotal** | **59** | **150** |
| | | | |
| **TOTAL** | **(Weapon + All Armor)** | **209** | **230** |

### Importance for Combat Logic
This baseline is critical for balancing the `Küzdelmek` (Combat) module.
- **Base Cap:** Any combat formula should account for players hitting these numbers.
- **Stat Distribution:** Defense (230) is slightly higher than Attack (209) at the very top end, meaning fights might be durable unless other modifiers (Skills, Buffs, Luck) are significant.
- **Buff Impact:** Consumables like *Kokain* (+25% Attack) or *Heroin* (+25% Defense) will scale off these large base numbers significantly.
