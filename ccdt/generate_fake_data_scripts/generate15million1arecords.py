import time
import random

# Target configuration
RECORD_COUNT = 15_000_000
OUTPUT_FILE = "table_1A_15M_version2.txt"

# Set your delimiter here (e.g., "|", "\t" for Tab, or ",")
DELIMITER = "|"

# Field headers for Table 1A
HEADERS = [
    "Record Type", "Constituent ID", "Individual Type", "Prefix", 
    "First Name", "Middle Name", "Last Name", "Suffix", "Appellation", 
    "Salutation", "Date of Birth", "No Mail Flag", "Deceased Flag"
]

# Sample data pools for fast lookups
FIRST_NAMES = ["James", "Mary", "John", "Patricia", "Robert", "Jennifer", "Michael", "Linda", "William", "Elizabeth"]
LAST_NAMES = ["Smith", "Johnson", "Williams", "Brown", "Jones", "Garcia", "Miller", "Davis", "Rodriguez", "Martinez"]
MIDDLE_INITIALS = ["A", "B", "C", "D", "E", "F", "G", "H", "I", "J"]
PREFIXES = ["Mr.", "Mrs.", "Ms.", "Dr.", "Hon."]
INDIVIDUAL_TYPES = ["Constituent", "Voter", "Donor", "VIP"]
FLAGS = ["Y", "N"]

def generate_line_1a(idx):
    """Generates a single delimited text row."""
    first = random.choice(FIRST_NAMES)
    last = random.choice(LAST_NAMES)
    middle = random.choice(MIDDLE_INITIALS)
    prefix = random.choice(PREFIXES)
    
    year = random.randint(1940, 2005)
    month = random.randint(1, 12)
    day = random.randint(1, 28)
    dob = f"{year}-{month:02d}-{day:02d}"

    fields = [
        "1A",                        # Record Type
        f"CST-{idx:09d}",            # Constituent ID
        random.choice(INDIVIDUAL_TYPES), # Individual Type
        prefix,                      # Prefix
        first,                       # First Name
        middle,                      # Middle Name
        last,                        # Last Name
        "",                          # Suffix
        f"The Honorable {first} {last}" if prefix == "Hon." else "", # Appellation
        f"{prefix} {last}",          # Salutation
        dob,                         # Date of Birth
        random.choice(FLAGS),        # No Mail Flag
        "N" if idx % 20 != 0 else "Y" # Deceased Flag
    ]

    return DELIMITER.join(fields) + "\n"

def main():
    print(f"Starting generation of {RECORD_COUNT:,} text records for Table 1A...")
    start_time = time.time()
    
    # High-performance 1MB file write buffer
    buffer_size = 1024 * 1024  
    batch_size = 100_000
    lines_batch = []

    with open(OUTPUT_FILE, mode="w", encoding="utf-8", buffering=buffer_size) as txtfile:
        # Write Header line
        txtfile.write(DELIMITER.join(HEADERS) + "\n")

        for i in range(1, RECORD_COUNT + 1):
            lines_batch.append(generate_line_1a(i))

            # Write batch of 100,000 lines
            if i % batch_size == 0:
                txtfile.writelines(lines_batch)
                lines_batch.clear()

                elapsed = time.time() - start_time
                percent = (i / RECORD_COUNT) * 100
                print(f"Progress: {i:,} / {RECORD_COUNT:,} ({percent:.0f}%) | Time elapsed: {elapsed:.1f}s")

        # Flush any remaining lines
        if lines_batch:
            txtfile.writelines(lines_batch)

    total_time = time.time() - start_time
    print(f"\nCompleted! Generated {RECORD_COUNT:,} text records in {OUTPUT_FILE}.")
    print(f"Total time taken: {total_time:.2f} seconds.")

if __name__ == "__main__":
    main()
