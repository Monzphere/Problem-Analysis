# **Zabbix Problem Analysis Module**

## This module was developed from version 7.0x.
## However, with some adjustments, it may be functional in other versions that support module deployment.
## To test in versions 6.0x, you need to change the manifest_version to 1.0.

This module provides a historical analysis of specific problems in Zabbix, offering a comparative report between the current and previous months. It helps users monitor issue trends and resolution efficiency.

<img width="1126" height="677" alt="image" src="https://github.com/user-attachments/assets/cbfee88c-208d-4687-9746-7114af6184e8" />
<img width="1064" height="691" alt="image" src="https://github.com/user-attachments/assets/5032246c-4dbd-4ae4-8123-ee10ccc693ea" />
<img width="944" height="592" alt="image" src="https://github.com/user-attachments/assets/24f293d6-a0a3-486f-8b91-2cbebe3ebf89" />
<img width="949" height="651" alt="image" src="https://github.com/user-attachments/assets/7668ce1f-690f-4753-9ea7-540437d134af" />
<img width="963" height="578" alt="image" src="https://github.com/user-attachments/assets/4b7892ef-4a24-43c9-904c-c5ff658e46e9" />
<img width="935" height="662" alt="image" src="https://github.com/user-attachments/assets/1548870e-56b3-455d-8b50-ccecc7726724" />






## **Features**
- **Problem Summary**: Displays the total number of incidents recorded in the current and previous months.
- **Resolution Time**: Shows the average resolution time and highlights percentage changes.
- **Acknowledgment (ACK) Analysis**: Tracks the number of acknowledged events and their corresponding percentage.
- **Trend Indicators**: Uses color-coded arrows (green for improvement, red for deterioration) to indicate changes in key metrics.

## **Example Report**
The image shows an analysis for the problem **"UNAVAILABLE BY ICMP PING"**, comparing April 2025 with March 2025:
- **Total Problems** decreased from 2,755 to **84** (-97.0%).
- **Avg. Resolution Time** increased from **2m to 2m** (+33.3%).
- **Events with ACK** remained at **0**.
- **ACK Percentage** remained at **0%**.

This module enhances incident management by providing insights into recurring issues, resolution effectiveness, and acknowledgment rates.


<img width="1037" height="800" alt="image" src="https://github.com/user-attachments/assets/92e856d5-f86c-4e0c-ac8b-5bffc9e3acc7" />
<img width="1043" height="693" alt="image" src="https://github.com/user-attachments/assets/a9481898-59da-44f1-98c3-b008185dfad2" />
<img width="1040" height="684" alt="image" src="https://github.com/user-attachments/assets/82d95188-0b8f-4870-ad3b-212fc1ae5a54" />
<img width="1039" height="774" alt="image" src="https://github.com/user-attachments/assets/8a1072e2-4c26-44a6-9501-b35a93edf52c" />
<img width="1040" height="817" alt="image" src="https://github.com/user-attachments/assets/78f970c4-4720-4799-85b9-3dd9b91063f3" />
<img width="1043" height="688" alt="image" src="https://github.com/user-attachments/assets/66ac6412-525d-4d14-bcad-bd6409fb6c65" />


