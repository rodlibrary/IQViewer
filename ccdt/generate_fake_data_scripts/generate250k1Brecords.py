import os
import random
import time

RECORD_TYPE = "1B"

TITLES = ["Mr.", "Mrs.", "Ms.", "Dr.", "Prof."]

ORGANIZATIONS = [
    "Acme Corp", "Global Logistics", "Apex Solutions", "Summit Media", 
    "Vanguard Tech", "Horizon Health", "Pioneer Systems", "Titanium Ltd"
]

STREETS = [
    "Main St", "High St", "Maple Ave", "Oak St", "Washington St", "Park Rd",
    "Cedar St", "Pine St", "Elm St", "View Rd", "Lake Ave", "Hill St"
]

CITIES = [
    "New York", "Los Angeles", "Chicago", "Houston", "Phoenix", "Philadelphia",
    "San Antonio", "San Diego", "Dallas", "San Jose", "Austin", "Jacksonville"
]

STATES = ["NY", "CA", "IL", "TX", "AZ", "PA", "FL", "OH", "NC", "MI"]

COUNTRIES = ["USA", "US"]

COUNTIES = ["Cook", "Harris", "Maricopa", "San Diego", "Orange", "Miami-Dade", "Dallas"]

AGENCY_CODES = ["AG01", "AG02", "AG03", "GOV", "STATE", "FED"]

def generate_records(file_path, total_records=250000):
    print(f"Generating {total_records:,} 1B records into '{file_path}'...")
    start_time = time.time()
    
    with open(file_path, "w", encoding="utf-8") as f:
        for i in range(1, total_records + 1):
            record_type = RECORD_TYPE                                        # 1. Record Type
            constituent_id = f"C{10000000 + i}"                              # 2. Constituent ID
            address_id = f"A{50000000 + i}"                                  # 3. Address ID
            address_type = random.choice(["Home", "Work", "Mailing"])         # 4. Address Type
            primary_flag = random.choice(["Y", "N"])                         # 5. Primary Flag
            default_address_flag = random.choice(["Y", "N"])                # 6. Default Address Flag
            title = random.choice(TITLES)                                    # 7. Title
            org_name = random.choice(ORGANIZATIONS)                          # 8. Organization Name
            address_1 = f"{random.randint(100, 9999)} {random.choice(STREETS)}" # 9. Address line 1
            address_2 = f"Apt {random.randint(1, 200)}" if random.random() < 0.3 else "" # 10. Address line 2
            address_3 = ""                                                   # 11. Address line 3
            address_4 = ""                                                   # 12. Address line 4
            city = random.choice(CITIES)                                     # 13. City
            state = random.choice(STATES)                                    # 14. State
            zip_code = f"{random.randint(10000, 99999):05d}"                 # 15. Zip Code
            carrier_route = f"C{random.randint(1, 99):02d}"                  # 16. Carrier Route
            county = random.choice(COUNTIES)                                 # 17. County
            country = random.choice(COUNTRIES)                               # 18. Country
            district = f"Dist-{random.randint(1, 20)}"                       # 19. District
            precinct = f"Pct-{random.randint(1, 100):03d}"                   # 20. Precinct
            no_mail_flag = random.choice(["0", "1"])                         # 21. No Mail Flag
            agency_code = random.choice(AGENCY_CODES)                        # 22. Agency Code

            # Join all 22 fields with pipe delimiters
            fields = [
                record_type, constituent_id, address_id, address_type,
                primary_flag, default_address_flag, title, org_name,
                address_1, address_2, address_3, address_4,
                city, state, zip_code, carrier_route,
                county, country, district, precinct,
                no_mail_flag, agency_code
            ]
            
            f.write("|".join(fields) + "\n")
            
            if i % 50000 == 0:
                print(f"  Generated {i:,} / {total_records:,} records...")

    elapsed = time.time() - start_time
    file_size_mb = os.path.getsize(file_path) / (1024 * 1024)
    print(f"\nDone! Created '{file_path}' in {elapsed:.2f} seconds ({file_size_mb:.2f} MB).")

if __name__ == "__main__":
    generate_records("test250k_1b.txt", total_records=250000)
