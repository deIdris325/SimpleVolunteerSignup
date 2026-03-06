# SignupBoard

A simple self-hosted signup board for tasks, events and volunteer slots.

Users can register for available slots, and once the limit is reached additional users are placed on a reserve list automatically.

## Features

- Simple signup system
- Automatic reserve list
- Admin panel for managing entries
- JSON based storage (no database required)
- Mobile friendly UI
- CSV export
- Self-hosted

---

# Screenshots

## User Interface

![SignupBoard Frontend](frontendUI.png)

Users can sign up for available slots.  
If all slots are filled, additional users are placed automatically in the reserve list.

---

## Admin Panel

![SignupBoard Admin](adminUI.png)

Admins can:

- create entries
- edit entries
- hide entries
- delete entries
- configure texts

---

# Installation

1. Upload the files to your webserver
2. Ensure PHP 8+ is installed
3. Make sure the following files are writable:
