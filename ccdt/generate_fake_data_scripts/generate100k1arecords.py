import random
import time

# Generate a unique timestamped filename to prevent duplicate file name errors
filename = f"scdif_100k_{int(time.time())}.txt"

first_names = ['John', 'Robert', 'David', 'Timothy', 'Jan', 'Garry', 'Marilyn', 'Kevin', 'Tammie']
last_names = ['Quorum', 'Anderson', 'Crislip', 'Johnson', 'Vanderpool', 'Ray', 'Davis', 'Hayes', 'Clark']
person_types = ['CS', 'AG', 'USER']
prefixes = ['Mr.', 'Ms.', 'Dr.']

print(f"Generating 100,000 tab-delimited records in '{filename}'...")

with open(filename, 'w', encoding='utf-8') as f:
    for i in range(100000):
        rec_type = '1A'
        person_id = str(200000 + i)  # Incremented range to avoid primary key collisions
        p_type = random.choice(person_types)
        prefix = random.choice(prefixes)
        fname = random.choice(first_names)
        mname = ""  # preserve tab position
        lname = random.choice(last_names)
        suffix = "II" if i % 10 == 0 else ""
        appellation = ""
        org_name = ""
        salutation = f"{prefix} {lname}"
        dob = "19250101" if i % 5 == 0 else ""
        no_mail = ""
        deceased = ""
        spouses = ""
        email_flag = "Y" if i % 2 == 0 else ""

        # Construct tab-delimited line matching working 1A file layout
        row = [
            rec_type, person_id, p_type, prefix, fname, mname, 
            lname, suffix, appellation, org_name, salutation, 
            dob, no_mail, deceased, spouses, email_flag
        ]
        f.write("\t".join(row) + "\n")

print(f"Done! Successfully created '{filename}'.")